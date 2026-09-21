<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Repository;

use App\Ingestion\Infrastructure\Query\PlanningOutcomeQueryGuard;
use App\Ingestion\Infrastructure\Query\PlanningUnitOutcomeSql;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** Writes completeness and provenance in the same transaction as source facts. */
final readonly class PlanningSourceStateWriter
{
    private const int OUTCOME_ORDER_BATCH_SIZE = 5000;

    public function __construct(private Connection $connection, private PlanningOutcomeQueryGuard $queryGuard)
    {
    }

    /** Serialize every fact/status/observation publication for one account. Call before source upserts. */
    public function lockAccount(string $companyId, string $accountId): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Planning account lock requires a transaction.');
        }
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d H:i:s');
        $this->connection->executeStatement(
            'INSERT INTO planning_ingestion_account_state (company_id, marketplace_account_id, generation, updated_at) VALUES (?, ?, 1, ?) ON CONFLICT (company_id, marketplace_account_id) DO NOTHING',
            [$companyId, $accountId, $now],
        );
        $generation = $this->connection->fetchOne(
            'SELECT generation FROM planning_ingestion_account_state WHERE company_id = ? AND marketplace_account_id = ? FOR UPDATE',
            [$companyId, $accountId],
        );
        if (!\is_int($generation) && !\is_string($generation)) {
            throw new \UnexpectedValueException('Planning account generation is unavailable.');
        }
    }

    /**
     * @param list<string>                                             $rawDocumentIds
     * @param list<string>                                             $sourceRowIds         Sales rows affected by a postings fetch
     * @param list<array{orderNumber: string, marketplaceSku: string}> $returnKeys           Orders affected by returns
     * @param list<?string>                                            $affectedOrderNumbers Orders whose sibling rows can change outcome
     */
    public function recordCompleted(
        string $companyId,
        string $accountId,
        string $kind,
        string $fromDate,
        string $toDate,
        string $contentHash,
        string $origin,
        array $rawDocumentIds,
        array $sourceRowIds = [],
        array $returnKeys = [],
        ?string $regularWindowFrom = null,
        ?string $regularWindowTo = null,
        array $affectedOrderNumbers = [],
    ): void {
        if (!\in_array($kind, ['postings', 'returns', 'catalog'], true) || !\in_array($origin, ['regular', 'rescan'], true)) {
            throw new \InvalidArgumentException('Unknown Planning source kind or sync origin.');
        }
        if (!$this->connection->isTransactionActive()) {
            $this->connection->transactional(function () use ($companyId, $accountId, $kind, $fromDate, $toDate, $contentHash, $origin, $rawDocumentIds, $sourceRowIds, $returnKeys, $regularWindowFrom, $regularWindowTo, $affectedOrderNumbers): void {
                $this->recordCompleted($companyId, $accountId, $kind, $fromDate, $toDate, $contentHash, $origin, $rawDocumentIds, $sourceRowIds, $returnKeys, $regularWindowFrom, $regularWindowTo, $affectedOrderNumbers);
            });

            return;
        }

        $moscow = new \DateTimeZone('Europe/Moscow');
        $clock = new \DateTimeImmutable('now', $moscow);
        $now = $clock->format('Y-m-d H:i:s');
        $this->lockAccount($companyId, $accountId);
        $baselineCompleted = $this->connection->fetchOne(
            'SELECT baseline_completed_at FROM planning_ingestion_account_state WHERE company_id = ? AND marketplace_account_id = ?',
            [$companyId, $accountId],
        );
        $observationOrigin = null === $baselineCompleted ? 'rescan' : $origin;
        if ((null === $regularWindowFrom) !== (null === $regularWindowTo)
            || (null !== $regularWindowFrom && $regularWindowFrom > $regularWindowTo)) {
            throw new \InvalidArgumentException('Invalid Planning regular window bounds.');
        }
        $prior = $this->connection->fetchAssociative(
            'SELECT content_hash, last_regular_complete_at FROM planning_ingestion_source_state WHERE company_id = ? AND marketplace_account_id = ? AND source_kind = ? AND from_date = ? AND to_date = ?',
            [$companyId, $accountId, $kind, $fromDate, $toDate],
        );
        $newRegularDay = 'regular' === $origin && 'catalog' !== $kind && (false === $prior || !\is_string($prior['last_regular_complete_at']) || !str_starts_with($prior['last_regular_complete_at'], $clock->format('Y-m-d')));
        $hasWindow = $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM planning_ingestion_source_state WHERE company_id = ? AND marketplace_account_id = ?)',
            [$companyId, $accountId],
        );
        $firstWindow = false === $prior && !\in_array($hasWindow, [true, 't', 1], true);
        $generationChanged = !$firstWindow && (false === $prior || $prior['content_hash'] !== $contentHash || $newRegularDay);
        if ($generationChanged) {
            $this->connection->executeStatement(
                'UPDATE planning_ingestion_account_state SET generation = generation + 1, updated_at = ? WHERE company_id = ? AND marketplace_account_id = ?',
                [$now, $companyId, $accountId],
            );
        }
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO planning_ingestion_source_state
                    (company_id, marketplace_account_id, source_kind, from_date, to_date, content_hash, last_complete_at, last_regular_complete_at, last_regular_window_to, last_origin)
                VALUES (:company, :account, :kind, :fromDate, :toDate, :hash, :now,
                        CASE WHEN :origin = 'regular' THEN :now::timestamp ELSE NULL END,
                        CASE WHEN :origin = 'regular' THEN :windowTo::date ELSE NULL END, :origin)
                ON CONFLICT (company_id, marketplace_account_id, source_kind, from_date, to_date)
                DO UPDATE SET content_hash = EXCLUDED.content_hash,
                              last_complete_at = EXCLUDED.last_complete_at,
                              last_regular_complete_at = COALESCE(EXCLUDED.last_regular_complete_at, planning_ingestion_source_state.last_regular_complete_at),
                              last_regular_window_to = GREATEST(EXCLUDED.last_regular_window_to, planning_ingestion_source_state.last_regular_window_to),
                              last_origin = EXCLUDED.last_origin
                SQL,
            [
                'company' => $companyId, 'account' => $accountId, 'kind' => $kind,
                'fromDate' => $fromDate, 'toDate' => $toDate, 'hash' => $contentHash,
                'now' => $now, 'origin' => $origin, 'windowTo' => $regularWindowTo,
            ],
        );
        $this->connection->executeStatement(
            'DELETE FROM planning_ingestion_source_raw_document WHERE company_id = ? AND marketplace_account_id = ? AND source_kind = ? AND from_date = ? AND to_date = ?',
            [$companyId, $accountId, $kind, $fromDate, $toDate],
        );
        if ([] !== $rawDocumentIds) {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO planning_ingestion_source_raw_document
                        (company_id, marketplace_account_id, source_kind, from_date, to_date, raw_document_id)
                    SELECT DISTINCT :company::uuid, :account::uuid, :kind, :fromDate::date, :toDate::date, raw_id::uuid
                    FROM jsonb_array_elements_text(:rawIds::jsonb) AS source(raw_id)
                    ON CONFLICT DO NOTHING
                    SQL,
                ['company' => $companyId, 'account' => $accountId, 'kind' => $kind, 'fromDate' => $fromDate, 'toDate' => $toDate,
                    'rawIds' => json_encode($rawDocumentIds, \JSON_THROW_ON_ERROR)],
            );
        }

        $today = $clock->format('Y-m-d');
        $completeRegularWindow = null !== $regularWindowFrom && $regularWindowTo === $today
            && $this->regularWindowComplete($companyId, $accountId, $kind, $regularWindowFrom, $regularWindowTo, $today);
        if ('regular' === $origin && 'catalog' !== $kind && $completeRegularWindow) {
            $checkedColumn = 'postings' === $kind ? 'postings_checked_at' : 'returns_checked_at';
            $wasCovered = $this->connection->fetchOne(
                "SELECT {$checkedColumn} FROM planning_ingestion_day_coverage WHERE company_id = ? AND marketplace_account_id = ? AND observation_date = ?",
                [$companyId, $accountId, $today],
            );
            if (null === $wasCovered || false === $wasCovered) {
                if (!$generationChanged) {
                    $this->connection->executeStatement(
                        'UPDATE planning_ingestion_account_state SET generation = generation + 1, updated_at = ? WHERE company_id = ? AND marketplace_account_id = ?',
                        [$now, $companyId, $accountId],
                    );
                    $generationChanged = true;
                }
            }
            $this->connection->executeStatement(
                "INSERT INTO planning_ingestion_day_coverage (company_id, marketplace_account_id, observation_date, {$checkedColumn}) VALUES (?, ?, ?, ?) ON CONFLICT (company_id, marketplace_account_id, observation_date) DO UPDATE SET {$checkedColumn} = EXCLUDED.{$checkedColumn}",
                [$companyId, $accountId, $today, $now],
            );
        }
        if (null === $baselineCompleted && 'regular' === $origin) {
            $baselineReady = $this->connection->fetchOne(
                'SELECT EXISTS (SELECT 1 FROM planning_ingestion_day_coverage WHERE company_id = ? AND marketplace_account_id = ? AND observation_date = ? AND postings_checked_at IS NOT NULL AND returns_checked_at IS NOT NULL)',
                [$companyId, $accountId, $today],
            );
            if (\in_array($baselineReady, [true, 't', 1], true)) {
                $this->connection->executeStatement(
                    'UPDATE planning_ingestion_account_state SET baseline_completed_at = ?, updated_at = ? WHERE company_id = ? AND marketplace_account_id = ?',
                    [$now, $now, $companyId, $accountId],
                );
                if (!$generationChanged) {
                    $this->connection->executeStatement(
                        'UPDATE planning_ingestion_account_state SET generation = generation + 1 WHERE company_id = ? AND marketplace_account_id = ?',
                        [$companyId, $accountId],
                    );
                }
            }
        }

        $this->observeAllocations($companyId, $accountId, $kind, $observationOrigin, $now, $rawDocumentIds[0] ?? null, $sourceRowIds, $returnKeys, $affectedOrderNumbers);
    }

    private function regularWindowComplete(string $companyId, string $accountId, string $kind, string $from, string $to, string $today): bool
    {
        $covered = $this->connection->fetchOne(
            <<<'SQL'
                SELECT NOT EXISTS (
                    SELECT 1 FROM generate_series(?::date, ?::date, INTERVAL '1 day') AS d(day)
                    WHERE NOT EXISTS (
                        SELECT 1 FROM planning_ingestion_source_state s
                        WHERE s.company_id = ? AND s.marketplace_account_id = ? AND s.source_kind = ?
                          AND s.from_date <= d.day::date AND s.to_date >= d.day::date
                          AND s.last_regular_complete_at >= ?::timestamp
                          AND s.last_regular_window_to = ?::date
                    )
                )
                SQL,
            [$from, $to, $companyId, $accountId, $kind, $today, $today],
        );

        return true === $covered || 't' === $covered || 1 === $covered;
    }

    public function beginReturnedOrders(): void
    {
        $this->connection->executeStatement('CREATE TEMP TABLE IF NOT EXISTS planning_return_keys (order_number TEXT NOT NULL, marketplace_sku TEXT NOT NULL, PRIMARY KEY (order_number, marketplace_sku)) ON COMMIT DROP');
        $this->connection->executeStatement('TRUNCATE planning_return_keys');
    }

    /**
     * Existing rows can move to another order on correction; their former siblings
     * must be observed after the upsert as well.
     *
     * @param list<string> $sourceRowIds
     *
     * @return list<?string>
     */
    public function previousSalesOrderNumbers(string $companyId, string $accountId, array $sourceRowIds): array
    {
        if ([] === $sourceRowIds) {
            return [];
        }

        $orders = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT DISTINCT s.order_number
                FROM jsonb_array_elements_text(?::jsonb) AS ids(source_row_id)
                JOIN sales_fact s
                  ON s.company_id = ? AND s.marketplace_account_id = ?
                 AND s.source_row_id = ids.source_row_id
                SQL,
            [json_encode($sourceRowIds, \JSON_THROW_ON_ERROR), $companyId, $accountId],
        );
        foreach ($orders as $order) {
            if (null !== $order && !\is_string($order)) {
                throw new \UnexpectedValueException('Planning sales order number is invalid.');
            }
        }

        return array_values(array_unique($orders, \SORT_REGULAR));
    }

    /** @param list<string> $sourceRowIds */
    public function stagePreviousReturnedOrders(string $companyId, string $accountId, array $sourceRowIds): void
    {
        foreach (array_chunk($sourceRowIds, 500) as $batch) {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO planning_return_keys (order_number, marketplace_sku)
                    SELECT DISTINCT r.order_number, r.marketplace_sku
                    FROM marketplace_return_fact r
                    WHERE r.company_id = ? AND r.marketplace_account_id = ? AND r.source_row_id IN (?)
                    ON CONFLICT (order_number, marketplace_sku) DO NOTHING
                    SQL,
                [$companyId, $accountId, $batch],
                [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, ArrayParameterType::STRING],
            );
        }
    }

    /** @param list<array{orderNumber: string, marketplaceSku: string}> $returnKeys */
    public function stageReturnedOrders(array $returnKeys): void
    {
        foreach (array_chunk($returnKeys, 500) as $batch) {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO planning_return_keys (order_number, marketplace_sku)
                    SELECT DISTINCT order_number, marketplace_sku
                    FROM jsonb_to_recordset(?::jsonb) AS k(order_number text, marketplace_sku text)
                    ON CONFLICT (order_number, marketplace_sku) DO NOTHING
                    SQL,
                [json_encode(array_map(static fn (array $key): array => [
                    'order_number' => $key['orderNumber'], 'marketplace_sku' => $key['marketplaceSku'],
                ], $batch), \JSON_THROW_ON_ERROR)],
            );
        }
    }

    public function observeStagedReturns(string $companyId, string $accountId, string $origin): void
    {
        $baselineCompleted = $this->connection->fetchOne(
            'SELECT baseline_completed_at FROM planning_ingestion_account_state WHERE company_id = ? AND marketplace_account_id = ?',
            [$companyId, $accountId],
        );
        $observationOrigin = null === $baselineCompleted ? 'rescan' : $origin;
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d H:i:s');
        $after = null;
        do {
            $afterFilter = null === $after ? '' : 'WHERE order_number > ?';
            $orders = $this->connection->fetchFirstColumn(
                "SELECT DISTINCT order_number FROM planning_return_keys {$afterFilter} ORDER BY order_number LIMIT ".self::OUTCOME_ORDER_BATCH_SIZE,
                null === $after ? [] : [$after],
            );
            if ([] === $orders) {
                break;
            }
            foreach ($orders as $order) {
                if (!\is_string($order)) {
                    throw new \UnexpectedValueException('Planning staged return key has no order number.');
                }
            }
            /* @var list<string> $orders */
            $this->observeAllocations($companyId, $accountId, 'returns', $observationOrigin, $now, null, [], [], $orders, true);
            $after = $orders[\count($orders) - 1];
        } while (self::OUTCOME_ORDER_BATCH_SIZE === \count($orders));
    }

    /**
     * @param list<string>                                             $sourceRowIds
     * @param list<array{orderNumber: string, marketplaceSku: string}> $returnKeys
     * @param list<?string>                                            $orderNumbers
     */
    private function observeAllocations(string $companyId, string $accountId, string $kind, string $origin, string $now, ?string $rawId, array $sourceRowIds, array $returnKeys, array $orderNumbers = [], bool $stagedReturns = false): void
    {
        if ([] === $sourceRowIds && [] === $returnKeys && [] === $orderNumbers && !$stagedReturns) {
            return;
        }

        if ([] === $orderNumbers && [] !== $returnKeys) {
            $orderNumbers = array_column($returnKeys, 'orderNumber');
        }
        if ([] === $orderNumbers && [] !== $sourceRowIds) {
            $orderNumbers = $this->connection->fetchFirstColumn(
                'SELECT DISTINCT order_number FROM sales_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id IN (?)',
                [$companyId, $accountId, $sourceRowIds],
                [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, ArrayParameterType::STRING],
            );
        }
        $orderNumbers = array_values(array_unique($orderNumbers, \SORT_REGULAR));
        $nonnullOrders = array_values(array_filter($orderNumbers, 'is_string'));
        $includeNullOrder = \in_array(null, $orderNumbers, true);
        $nullOrderIds = $includeNullOrder ? array_values(array_unique($sourceRowIds)) : [];
        $orderPredicates = [];
        if ([] !== $nonnullOrders) {
            $orderPredicates[] = 'order_number IN (:affectedOrders)';
        }
        if ([] !== $nullOrderIds) {
            $orderPredicates[] = '(order_number IS NULL AND source_row_id IN (:nullOrderIds))';
        }
        $orderPredicate = [] === $orderPredicates ? 'FALSE' : implode(' OR ', $orderPredicates);
        $affected = [] !== $orderNumbers
            ? 'EXISTS (SELECT 1 FROM affected_orders k WHERE k.order_number IS NOT DISTINCT FROM b.order_number)'
            : ([] !== $sourceRowIds
                ? 'b.source_row_id IN (:ids)'
                : 'EXISTS (SELECT 1 FROM affected_returns k WHERE k.order_number = b.order_number)');
        $unitCte = PlanningUnitOutcomeSql::cte($orderPredicate);
        $affectedReturnsSource = $stagedReturns
            ? 'SELECT order_number, marketplace_sku FROM planning_return_keys'.([] !== $nonnullOrders ? ' WHERE order_number IN (:affectedOrders)' : '')
            : 'SELECT DISTINCT order_number, marketplace_sku FROM jsonb_to_recordset(:returnKeys::jsonb) AS k(order_number text, marketplace_sku text)';
        $runId = $this->connection->fetchOne(
            'UPDATE planning_ingestion_account_state SET observation_run = observation_run + 1 WHERE company_id = ? AND marketplace_account_id = ? RETURNING observation_run',
            [$companyId, $accountId],
        );
        if (!\is_int($runId) && !\is_string($runId)) {
            throw new \UnexpectedValueException('Planning observation run ID is unavailable.');
        }
        $sql = <<<SQL
            WITH {$unitCte}, affected_orders AS MATERIALIZED (
                SELECT DISTINCT value AS order_number FROM jsonb_array_elements_text(:orderNumbers::jsonb) AS k(value)
            ), affected_returns AS MATERIALIZED (
                {$affectedReturnsSource}
            ), return_raw AS MATERIALIZED (
                SELECT DISTINCT ON (r.company_id, r.marketplace_account_id, r.order_number, r.marketplace_sku, r.return_type)
                       r.company_id, r.marketplace_account_id, r.order_number, r.marketplace_sku,
                       r.return_type, r.raw_document_id
                FROM marketplace_return_fact r
                JOIN affected_returns k ON k.order_number = r.order_number AND k.marketplace_sku = r.marketplace_sku
                WHERE r.company_id = :company AND r.marketplace_account_id = :account
                ORDER BY r.company_id, r.marketplace_account_id, r.order_number, r.marketplace_sku,
                         r.return_type, r.visual_status_changed_at DESC, r.source_row_id DESC
            ), affected_sources AS MATERIALIZED (
                SELECT DISTINCT company_id, marketplace_account_id, source_row_id FROM unit_outcome
            ), latest_runs AS MATERIALIZED (
                SELECT state.company_id, state.marketplace_account_id, state.source_row_id, state.last_seen_run
                FROM planning_ingestion_source_row_run state
                JOIN affected_sources affected
                  ON affected.company_id = state.company_id
                 AND affected.marketplace_account_id = state.marketplace_account_id
                 AND affected.source_row_id = state.source_row_id
            ), prior_same_outcome AS MATERIALIZED (
                SELECT old.company_id, old.marketplace_account_id, old.source_row_id, old.allocation_key, old.outcome,
                       old.first_known_outcome_at, old.first_regularly_observed_at, old.date_lineage,
                       ROW_NUMBER() OVER (
                           PARTITION BY old.company_id, old.marketplace_account_id, old.source_row_id, old.outcome
                           ORDER BY old.allocation_key::bigint
                       ) AS outcome_position
                FROM planning_ingestion_resolution_observation old
                JOIN latest_runs latest
                  ON latest.company_id = old.company_id
                 AND latest.marketplace_account_id = old.marketplace_account_id
                 AND latest.source_row_id = old.source_row_id
                 AND latest.last_seen_run = old.last_seen_run
            ), current_outcome_counts AS MATERIALIZED (
                SELECT company_id, marketplace_account_id, source_row_id, outcome,
                       MAX(outcome_position) AS quantity
                FROM unit_outcome
                GROUP BY company_id, marketplace_account_id, source_row_id, outcome
            ), consumed_prior AS MATERIALIZED (
                SELECT old.company_id, old.marketplace_account_id, old.source_row_id,
                       old.allocation_key, old.outcome
                FROM prior_same_outcome old
                JOIN current_outcome_counts current_count
                  ON current_count.company_id = old.company_id
                 AND current_count.marketplace_account_id = old.marketplace_account_id
                 AND current_count.source_row_id = old.source_row_id
                 AND current_count.outcome = old.outcome
                 AND old.outcome_position <= current_count.quantity
            ), candidates AS MATERIALIZED (
            SELECT b.company_id, b.marketplace_account_id, b.source_row_id, b.allocation_key, b.outcome,
                   dates.first_known_outcome_at, dates.first_regularly_observed_at, dates.date_lineage,
                   dates.source_priority,
                   :origin = 'regular' AND evidence.recent_terminal
                     AND NOT (previous_outcome.has_prior
                              AND sale.business_date < account_state.baseline_completed_at::date) AS can_date_new_unit,
                   CASE WHEN :kind = 'returns' THEN COALESCE(return_raw.raw_document_id, sale.raw_document_id)
                        ELSE COALESCE(sale.raw_document_id, :rawId::uuid) END AS raw_document_id
            FROM unit_outcome b
            JOIN sales_fact sale
              ON sale.company_id = b.company_id AND sale.marketplace_account_id = b.marketplace_account_id
             AND sale.source_row_id = b.source_row_id
            JOIN planning_ingestion_account_state account_state
              ON account_state.company_id = b.company_id AND account_state.marketplace_account_id = b.marketplace_account_id
            LEFT JOIN consumed_prior consumed_delivered
              ON consumed_delivered.company_id = b.company_id
             AND consumed_delivered.marketplace_account_id = b.marketplace_account_id
             AND consumed_delivered.source_row_id = b.source_row_id
             AND consumed_delivered.allocation_key = b.allocation_key AND consumed_delivered.outcome = 'D'
            LEFT JOIN planning_ingestion_resolution_observation prior
              ON prior.company_id = b.company_id
             AND prior.marketplace_account_id = b.marketplace_account_id
             AND prior.source_row_id = b.source_row_id
             AND prior.allocation_key = b.allocation_key AND prior.outcome = 'D'
             AND consumed_delivered.source_row_id IS NULL
            LEFT JOIN consumed_prior consumed_returned
              ON consumed_returned.company_id = b.company_id
             AND consumed_returned.marketplace_account_id = b.marketplace_account_id
             AND consumed_returned.source_row_id = b.source_row_id
             AND consumed_returned.allocation_key = b.allocation_key AND consumed_returned.outcome = 'R'
            LEFT JOIN planning_ingestion_resolution_observation prior_return
              ON prior_return.company_id = b.company_id
             AND prior_return.marketplace_account_id = b.marketplace_account_id
             AND prior_return.source_row_id = b.source_row_id
             AND prior_return.allocation_key = b.allocation_key AND prior_return.outcome = 'R'
             AND consumed_returned.source_row_id IS NULL
            LEFT JOIN consumed_prior consumed_same
              ON consumed_same.company_id = b.company_id
             AND consumed_same.marketplace_account_id = b.marketplace_account_id
             AND consumed_same.source_row_id = b.source_row_id
             AND consumed_same.allocation_key = b.allocation_key AND consumed_same.outcome = b.outcome
            LEFT JOIN planning_ingestion_resolution_observation prior_historical
              ON prior_historical.company_id = b.company_id
             AND prior_historical.marketplace_account_id = b.marketplace_account_id
             AND prior_historical.source_row_id = b.source_row_id
             AND prior_historical.allocation_key = b.allocation_key AND prior_historical.outcome = b.outcome
             AND consumed_same.source_row_id IS NULL
            LEFT JOIN prior_same_outcome prior_same
              ON prior_same.company_id = b.company_id
             AND prior_same.marketplace_account_id = b.marketplace_account_id
             AND prior_same.source_row_id = b.source_row_id
             AND prior_same.outcome = b.outcome
             AND prior_same.outcome_position = b.outcome_position
            CROSS JOIN LATERAL (
                SELECT EXISTS (
                    SELECT 1 FROM planning_ingestion_resolution_observation old
                    WHERE old.company_id = b.company_id AND old.marketplace_account_id = b.marketplace_account_id
                      AND old.source_row_id = b.source_row_id AND old.allocation_key = b.allocation_key
                      AND old.outcome <> b.outcome
                ) AS has_prior
            ) previous_outcome
            CROSS JOIN LATERAL (
                SELECT COALESCE(account_state.baseline_completed_at IS NOT NULL AND (
                    sale.business_date > account_state.baseline_completed_at::date
                    OR EXISTS (
                        SELECT 1 FROM marketplace_posting_status terminal
                        WHERE terminal.company_id = b.company_id
                          AND terminal.marketplace_account_id = b.marketplace_account_id
                          AND terminal.posting_number = sale.posting_number
                          AND terminal.observed_at > account_state.baseline_completed_at
                          AND ((b.outcome IN ('D', 'R') AND terminal.status = 'delivered'
                                AND terminal.substatus IN ('posting_delivered', 'posting_received'))
                            OR (b.outcome IN ('T1', 'T2', 'P') AND terminal.status = 'cancelled'
                                AND terminal.substatus = 'posting_canceled'))
                          AND EXISTS (
                              SELECT 1 FROM marketplace_posting_status active
                              WHERE active.company_id = terminal.company_id
                                AND active.marketplace_account_id = terminal.marketplace_account_id
                                AND active.posting_number = terminal.posting_number
                                AND active.observed_at < terminal.observed_at
                                AND ((active.status = 'awaiting_packaging' AND active.substatus = 'posting_created')
                                  OR (active.status = 'awaiting_deliver' AND active.substatus = 'posting_transferring_to_delivery')
                                  OR (active.status = 'delivering'
                                      AND active.substatus IN ('posting_in_pickup_point', 'posting_on_way_to_city')))
                          )
                    )
                ), FALSE) AS recent_terminal
            ) evidence
            CROSS JOIN LATERAL (
                SELECT CASE WHEN prior_same.first_known_outcome_at IS NOT NULL THEN prior_same.first_known_outcome_at
                            WHEN prior_historical.first_known_outcome_at IS NOT NULL
                            THEN prior_historical.first_known_outcome_at
                            WHEN b.outcome = 'R' AND prior.first_known_outcome_at IS NOT NULL THEN prior.first_known_outcome_at
                            WHEN b.outcome = 'D' AND prior_return.first_known_outcome_at IS NOT NULL THEN prior_return.first_known_outcome_at
                            WHEN prior_same.source_row_id IS NOT NULL OR prior_historical.source_row_id IS NOT NULL
                              OR (b.outcome = 'R' AND prior.source_row_id IS NOT NULL)
                              OR (b.outcome = 'D' AND prior_return.source_row_id IS NOT NULL) THEN NULL
                            WHEN previous_outcome.has_prior
                                 AND sale.business_date < account_state.baseline_completed_at::date THEN NULL
                            WHEN :origin = 'regular' AND evidence.recent_terminal
                            THEN :now::timestamp ELSE NULL END AS first_known_outcome_at,
                       CASE WHEN prior_same.first_known_outcome_at IS NOT NULL
                            THEN CASE WHEN b.outcome IN ('D', 'R') THEN prior_same.first_regularly_observed_at ELSE NULL END
                            WHEN prior_historical.first_known_outcome_at IS NOT NULL
                            THEN CASE WHEN b.outcome IN ('D', 'R') THEN prior_historical.first_regularly_observed_at ELSE NULL END
                            WHEN b.outcome = 'R' AND prior.first_known_outcome_at IS NOT NULL
                            THEN prior.first_regularly_observed_at
                            WHEN b.outcome = 'D' AND prior_return.first_known_outcome_at IS NOT NULL
                            THEN prior_return.first_regularly_observed_at
                            WHEN prior_same.source_row_id IS NOT NULL OR prior_historical.source_row_id IS NOT NULL
                              OR (b.outcome = 'R' AND prior.source_row_id IS NOT NULL)
                              OR (b.outcome = 'D' AND prior_return.source_row_id IS NOT NULL) THEN NULL
                            WHEN previous_outcome.has_prior
                                 AND sale.business_date < account_state.baseline_completed_at::date THEN NULL
                            WHEN b.outcome IN ('D', 'R') AND :origin = 'regular' AND evidence.recent_terminal
                            THEN :now::timestamp ELSE NULL END AS first_regularly_observed_at,
                       CASE WHEN prior_same.first_known_outcome_at IS NOT NULL THEN prior_same.date_lineage
                            WHEN prior_historical.first_known_outcome_at IS NOT NULL THEN prior_historical.date_lineage
                            WHEN b.outcome = 'R' AND prior.first_known_outcome_at IS NOT NULL THEN prior.date_lineage
                            WHEN b.outcome = 'D' AND prior_return.first_known_outcome_at IS NOT NULL THEN prior_return.date_lineage
                            WHEN prior_same.source_row_id IS NOT NULL OR prior_historical.source_row_id IS NOT NULL
                              OR (b.outcome = 'R' AND prior.source_row_id IS NOT NULL)
                              OR (b.outcome = 'D' AND prior_return.source_row_id IS NOT NULL) THEN NULL
                            WHEN previous_outcome.has_prior
                                 AND sale.business_date < account_state.baseline_completed_at::date THEN NULL
                            WHEN :origin = 'regular' AND evidence.recent_terminal
                            THEN CONCAT(:runId::text, ':', b.outcome, ':', b.allocation_key) ELSE NULL END AS date_lineage,
                       CASE WHEN prior_same.first_known_outcome_at IS NOT NULL THEN 1
                            WHEN prior_historical.first_known_outcome_at IS NOT NULL THEN 2
                            WHEN (b.outcome = 'R' AND prior.first_known_outcome_at IS NOT NULL)
                              OR (b.outcome = 'D' AND prior_return.first_known_outcome_at IS NOT NULL) THEN 3
                            ELSE 4 END AS source_priority
            ) dates
            LEFT JOIN return_raw
              ON :kind = 'returns'
             AND return_raw.company_id = b.company_id AND return_raw.marketplace_account_id = b.marketplace_account_id
             AND return_raw.order_number = b.order_number AND return_raw.marketplace_sku = b.marketplace_sku
             AND return_raw.return_type = CASE WHEN b.outcome = 'R' THEN 'ClientReturn' ELSE 'Cancellation' END
            WHERE b.company_id = :company
              AND b.marketplace_account_id = :account
              AND b.outcome IS NOT NULL
              AND {$affected}
            ), ranked AS (
                SELECT candidates.*,
                       ROW_NUMBER() OVER (
                           PARTITION BY company_id, marketplace_account_id, source_row_id, date_lineage
                           ORDER BY source_priority, allocation_key::bigint
                       ) AS lineage_position
                FROM candidates
            ), resolved AS (
                SELECT ranked.*,
                       CASE WHEN date_lineage IS NULL OR lineage_position = 1 THEN first_known_outcome_at
                            WHEN can_date_new_unit THEN :now::timestamp ELSE NULL END AS resolved_known_at,
                       CASE WHEN date_lineage IS NULL OR lineage_position = 1 THEN first_regularly_observed_at
                            WHEN can_date_new_unit AND outcome IN ('D', 'R') THEN :now::timestamp ELSE NULL END AS resolved_regular_at,
                       CASE WHEN date_lineage IS NULL OR lineage_position = 1 THEN date_lineage
                            WHEN can_date_new_unit THEN CONCAT(:runId::text, ':', outcome, ':', allocation_key)
                            ELSE NULL END AS resolved_lineage
                FROM ranked
            )
            INSERT INTO planning_ingestion_resolution_observation
                (company_id, marketplace_account_id, source_row_id, allocation_key, outcome, first_observed_at, undated_since_at,
                 first_known_outcome_at, first_regularly_observed_at, date_lineage, source_event_at,
                 backfill, raw_document_id, last_seen_run)
            SELECT company_id, marketplace_account_id, source_row_id, allocation_key, outcome, :now::timestamp,
                   CASE WHEN resolved_known_at IS NULL THEN :now::timestamp ELSE NULL END,
                   resolved_known_at, resolved_regular_at, resolved_lineage, NULL,
                   (:origin = 'rescan' OR resolved_known_at IS NULL), raw_document_id, :runId::bigint
            FROM resolved
            ON CONFLICT (company_id, marketplace_account_id, source_row_id, allocation_key, outcome)
            DO UPDATE SET undated_since_at = CASE
                              WHEN EXCLUDED.first_known_outcome_at IS NOT NULL THEN NULL
                              WHEN planning_ingestion_resolution_observation.first_known_outcome_at IS NOT NULL
                                OR planning_ingestion_resolution_observation.last_seen_run <> COALESCE((
                                    SELECT last_seen_run FROM planning_ingestion_source_row_run previous_run
                                    WHERE previous_run.company_id = EXCLUDED.company_id
                                      AND previous_run.marketplace_account_id = EXCLUDED.marketplace_account_id
                                      AND previous_run.source_row_id = EXCLUDED.source_row_id
                                ), 0) THEN EXCLUDED.first_observed_at
                              ELSE COALESCE(planning_ingestion_resolution_observation.undated_since_at, planning_ingestion_resolution_observation.first_observed_at)
                          END,
                          first_known_outcome_at = EXCLUDED.first_known_outcome_at,
                          first_regularly_observed_at = EXCLUDED.first_regularly_observed_at,
                          date_lineage = EXCLUDED.date_lineage,
                          backfill = EXCLUDED.backfill,
                          raw_document_id = COALESCE(EXCLUDED.raw_document_id, planning_ingestion_resolution_observation.raw_document_id),
                          last_seen_run = EXCLUDED.last_seen_run
            SQL;
        $params = ['company' => $companyId, 'account' => $accountId, 'kind' => $kind, 'now' => $now, 'origin' => $origin, 'rawId' => $rawId, 'runId' => $runId, 'orderNumbers' => json_encode(array_values(array_unique($orderNumbers, \SORT_REGULAR)), \JSON_THROW_ON_ERROR)];
        if ([] !== $nonnullOrders) {
            $params['affectedOrders'] = $nonnullOrders;
        }
        if ([] !== $nullOrderIds) {
            $params['nullOrderIds'] = $nullOrderIds;
        }
        if (!$stagedReturns) {
            $params['returnKeys'] = '[]';
        }
        $types = [];
        if ([] !== $nonnullOrders) {
            $types['affectedOrders'] = ArrayParameterType::STRING;
        }
        if ([] !== $nullOrderIds) {
            $types['nullOrderIds'] = ArrayParameterType::STRING;
        }
        if ([] !== $sourceRowIds && [] === $orderNumbers) {
            $params['ids'] = array_values(array_unique($sourceRowIds));
            $types['ids'] = ArrayParameterType::STRING;
        } else {
            $params['returnKeys'] = json_encode(array_map(static fn (array $key): array => [
                'order_number' => $key['orderNumber'], 'marketplace_sku' => $key['marketplaceSku'],
            ], $returnKeys), \JSON_THROW_ON_ERROR);
        }
        $markerPredicate = [] !== $orderNumbers ? $orderPredicate : ([] !== $sourceRowIds ? 'source_row_id IN (:ids)' : 'FALSE');
        $markerSql = <<<SQL
            INSERT INTO planning_ingestion_source_row_run
                (company_id, marketplace_account_id, source_row_id, last_seen_run)
            SELECT DISTINCT company_id, marketplace_account_id, source_row_id, :runId::bigint
            FROM sales_fact
            WHERE company_id = :company AND marketplace_account_id = :account
              AND ({$markerPredicate})
            ON CONFLICT (company_id, marketplace_account_id, source_row_id)
            DO UPDATE SET last_seen_run = EXCLUDED.last_seen_run
            SQL;
        $markerParams = ['company' => $companyId, 'account' => $accountId, 'runId' => $runId];
        $markerTypes = [];
        foreach (['affectedOrders', 'nullOrderIds', 'ids'] as $key) {
            if (isset($params[$key])) {
                $markerParams[$key] = $params[$key];
                $markerTypes[$key] = ArrayParameterType::STRING;
            }
        }
        $this->queryGuard->write(function () use ($sql, $params, $types, $markerSql, $markerParams, $markerTypes): void {
            $this->connection->executeStatement($sql, $params, $types);
            $this->connection->executeStatement($markerSql, $markerParams, $markerTypes);
        });
    }
}
