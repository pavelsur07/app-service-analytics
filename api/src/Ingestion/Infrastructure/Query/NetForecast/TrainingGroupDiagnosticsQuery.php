<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class TrainingGroupDiagnosticsQuery
{
    public function __construct(private Connection $connection, private NetTrainingLimits $limits = new NetTrainingLimits())
    {
    }

    public function build(string $companyId, string $accountId, NetTrainingContext $context, NetTrainingRequest $request, NetTrainingPopulation $population, int $limit = 50, ?NetTrainingCursor $cursor = null): QueryBuilder
    {
        $context->assertScope($companyId, $accountId);
        if ($limit < 1 || $limit > 200 || null === $context->snapshotId) {
            throw new \InvalidArgumentException('Diagnostics require a pinned RR context and a limit in 1..200.');
        }
        $cursor?->assertContext($context, $request, $population);
        $returns = NetTrainingPopulation::Returns === $population;
        $ctes = NetTrainingSourceSql::ctes($returns).', '.($returns ? ReturnTrainingAggregateQuery::populationCtes() : NetTrainingOutcomeSql::ctes().', '.GrossTrainingAggregateQuery::populationCtes());
        $groups = $returns ? <<<'SQL'
            SELECT DISTINCT COALESCE(r.origin::text, g.business_date::text) AS date_key, g.order_number, g.order_key, g.marketplace_sku,
                   (g.order_number IS NULL)::int AS order_missing, COALESCE(r.eligible, FALSE) AS eligible,
                   CASE WHEN r.eligible THEN NULL WHEN types.unknown_type THEN 'unknown_return_type' ELSE COALESCE(origin.reason, r.exclusion_reason, 'not_return_candidate') END AS exclusion_reason
            FROM candidate_groups g LEFT JOIN return_groups r ON r.order_number IS NOT DISTINCT FROM g.order_number AND r.marketplace_sku = g.marketplace_sku
            LEFT JOIN return_group_proof types ON types.order_number IS NOT DISTINCT FROM g.order_number AND types.marketplace_sku = g.marketplace_sku
            LEFT JOIN (
                SELECT m.order_number, m.marketplace_sku,
                       CASE WHEN COUNT(DISTINCT m.delivery_origin) > 1 THEN 'ambiguous_delivery_allocation'
                            WHEN NOT BOOL_AND(COALESCE(p.terminal_observed, FALSE)) THEN 'delivery_observation_unknown' ELSE NULL END AS reason
                FROM return_members m LEFT JOIN posting_proof p ON p.posting_number = m.posting_number
                GROUP BY m.order_number, m.marketplace_sku
            ) origin ON origin.order_number IS NOT DISTINCT FROM g.order_number AND origin.marketplace_sku = g.marketplace_sku
            SQL : <<<'SQL'
            SELECT g.business_date::text AS date_key, g.order_number, g.order_key, g.marketplace_sku,
                   (g.order_number IS NULL)::int AS order_missing, COALESCE(d.eligible, FALSE) AS eligible,
                   CASE WHEN NOT COALESCE(source.valid, FALSE) THEN 'source_history_unavailable'
                        WHEN COALESCE(outcome.unknown_quantity, 0) > 0 THEN 'unknown_outcome'
                        WHEN m.p95_seconds IS NULL THEN 'maturity_unavailable'
                        WHEN NOT COALESCE(d.eligible, FALSE) THEN 'cohort_not_mature' ELSE NULL END AS exclusion_reason
            FROM candidate_groups g LEFT JOIN gross_days d USING (business_date)
            LEFT JOIN gross_source_days source USING (business_date) LEFT JOIN gross_outcome_days outcome USING (business_date)
            CROSS JOIN gross_maturity m
            WHERE g.business_date >= CAST(:grossFrom AS date) AND g.business_date < CAST(:grossTo AS date)
            SQL;
        $source = <<<SQL
            WITH {$ctes}, diagnostic_context AS MATERIALIZED (
                SELECT txid_current_snapshot()::text = :diagnosticSnapshot
                  AND current_setting('transaction_read_only') = 'on'
                  AND current_setting('transaction_isolation') IN ('repeatable read', 'serializable') AS ok
            ), diagnostic_candidates AS MATERIALIZED (
                {$groups}
            ), diagnostic_rows AS (
                SELECT g.date_key, g.order_number, g.order_key, g.marketplace_sku, g.order_missing, g.eligible, g.exclusion_reason, TRUE AS input_complete
                FROM diagnostic_candidates g CROSS JOIN diagnostic_context c CROSS JOIN input_budget b WHERE c.ok AND b.ok
                UNION ALL
                SELECT NULL::text, NULL::text, NULL::text, NULL::text, 0, FALSE,
                       CASE WHEN NOT c.ok THEN 'diagnostic_snapshot_unavailable' ELSE 'input_budget_exceeded' END, FALSE
                FROM diagnostic_context c CROSS JOIN input_budget b WHERE NOT c.ok OR NOT b.ok
            )
            SELECT date_key, order_number, order_key, marketplace_sku, order_missing, eligible, exclusion_reason, input_complete FROM diagnostic_rows
            SQL;
        $query = $this->connection->createQueryBuilder()->select('d.date_key', 'd.order_number', 'd.order_key', 'd.marketplace_sku', 'd.order_missing', 'd.eligible', 'd.exclusion_reason', 'd.input_complete')->from('('.$source.')', 'd')
            ->orderBy('d.date_key COLLATE "C"')->addOrderBy('d.order_missing')->addOrderBy('d.order_key COLLATE "C"')->addOrderBy('d.marketplace_sku COLLATE "C"')->setMaxResults($limit + 1)->setParameter('diagnosticSnapshot', $context->snapshotId);
        if (null !== $cursor) {
            $query->andWhere('(NOT d.input_complete OR (d.date_key COLLATE "C", d.order_missing, d.order_key COLLATE "C", d.marketplace_sku COLLATE "C") > (:cursorDate, :cursorMissing, :cursorOrder, :cursorSku))')
                ->setParameter('cursorDate', $cursor->dateKey)->setParameter('cursorMissing', $cursor->orderMissing ? 1 : 0)->setParameter('cursorOrder', $cursor->orderKey)->setParameter('cursorSku', $cursor->marketplaceSku);
        }

        return NetTrainingSourceSql::bind($query, $context, $request, $this->limits);
    }

    /** @param array<string, mixed> $row */
    public static function mapRow(array $row): TrainingGroupDiagnosticRow
    {
        return new TrainingGroupDiagnosticRow(NetTrainingValue::nullableString($row['date_key']), NetTrainingValue::nullableString($row['order_number']), NetTrainingValue::nullableString($row['order_key']), NetTrainingValue::nullableString($row['marketplace_sku']), 1 === NetTrainingValue::integer($row['order_missing']), NetTrainingValue::boolean($row['eligible']), NetTrainingValue::nullableString($row['exclusion_reason']), NetTrainingValue::boolean($row['input_complete']));
    }
}
