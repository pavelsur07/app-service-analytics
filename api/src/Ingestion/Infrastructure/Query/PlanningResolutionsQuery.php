<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class PlanningResolutionsQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * An undated current outcome first seen after the window opened may have
     * occurred on any day in that window. Keep the affected SKU incomplete.
     *
     * @param list<string> $skus
     *
     * @phpstan-impure
     */
    public function hasUndatedCurrentOutcome(string $companyId, string $accountId, array $skus, \DateTimeImmutable $from, \DateTimeImmutable $to, string $axisColumn): bool
    {
        $unknown = $this->undatedCurrentOutcomeQuery($companyId, $accountId, $skus, $from, $to, $axisColumn)->executeQuery()->fetchOne();

        return \in_array($unknown, [true, 't', 1], true);
    }

    /** @param list<string> $skus */
    public function undatedCurrentOutcomeQuery(string $companyId, string $accountId, array $skus, \DateTimeImmutable $from, \DateTimeImmutable $to, string $axisColumn): QueryBuilder
    {
        $this->assertAxis($axisColumn);
        $eligible = <<<SQL
            eligible_rows AS MATERIALIZED (
                SELECT DISTINCT s.source_row_id
                FROM sales_fact s
                JOIN planning_ingestion_resolution_observation o
                  ON o.company_id = s.company_id AND o.marketplace_account_id = s.marketplace_account_id
                 AND o.source_row_id = s.source_row_id
                WHERE s.company_id = :company AND s.marketplace_account_id = :account
                  AND s.marketplace_sku IN (:skus)
                  AND s.business_date < :toExclusive
                  AND o.{$axisColumn} IS NULL AND o.undated_since_at >= :from
            )
            SQL;
        $unitCte = PlanningUnitOutcomeSql::cte('source_row_id IN (SELECT source_row_id FROM eligible_rows)');
        $outcomeFilter = 'first_regularly_observed_at' === $axisColumn ? "AND b.outcome IN ('D', 'R')" : '';
        $sql = <<<SQL
            WITH {$eligible}, {$unitCte}
            SELECT EXISTS (
                SELECT 1 FROM unit_outcome b
                JOIN planning_ingestion_resolution_observation o
                  ON o.company_id = b.company_id AND o.marketplace_account_id = b.marketplace_account_id
                 AND o.source_row_id = b.source_row_id AND o.allocation_key = b.allocation_key AND o.outcome = b.outcome
                WHERE b.marketplace_sku IN (:skus)
                  AND o.{$axisColumn} IS NULL AND o.undated_since_at >= :from
                  {$outcomeFilter}
            ) AS has_undated
            SQL;

        return $this->connection->createQueryBuilder()->select('has_undated')->from("({$sql})", 'undated_quality')
            ->setParameter('company', $companyId)->setParameter('account', $accountId)
            ->setParameter('skus', $skus, ArrayParameterType::STRING)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('toExclusive', $to->modify('+1 day')->format('Y-m-d'));
    }

    /** @param list<string> $skus */
    public function totals(string $companyId, string $accountId, array $skus, \DateTimeImmutable $from, \DateTimeImmutable $to, string $axisColumn): QueryBuilder
    {
        $this->assertAxis($axisColumn);
        $unitCte = PlanningUnitOutcomeSql::cte('source_row_id IN (SELECT source_row_id FROM eligible_rows)');
        $eligible = $this->eligibleRowsSql($axisColumn);

        $source = <<<SQL
            (
                WITH {$eligible}, {$unitCte}
                SELECT b.marketplace_sku,
                       COALESCE(SUM(b.quantity) FILTER (WHERE b.outcome = 'D'), 0)::bigint AS delivered,
                       COALESCE(SUM(b.quantity) FILTER (WHERE b.outcome = 'R'), 0)::bigint AS returned,
                       COALESCE(SUM(b.quantity) FILTER (WHERE b.outcome = 'T1'), 0)::bigint AS pre_handover_no_buy,
                       COALESCE(SUM(b.quantity) FILTER (WHERE b.outcome = 'T2'), 0)::bigint AS post_handover_no_buy,
                       COALESCE(SUM(b.quantity) FILTER (WHERE b.outcome = 'P'), 0)::bigint AS other_terminal_no_buy
                FROM unit_outcome b
                JOIN planning_ingestion_resolution_observation o
                  ON o.company_id = b.company_id AND o.marketplace_account_id = b.marketplace_account_id
                 AND o.source_row_id = b.source_row_id AND o.allocation_key = b.allocation_key AND o.outcome = b.outcome
                WHERE b.marketplace_sku IN (:skus)
                  AND o.{$axisColumn} >= :from AND o.{$axisColumn} < :toExclusive
                GROUP BY b.marketplace_sku
            ) resolution_totals
            SQL;

        return $this->connection->createQueryBuilder()->select('*')->from($source)
            ->setParameter('company', $companyId)->setParameter('account', $accountId)
            ->setParameter('skus', $skus, ArrayParameterType::STRING)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('toExclusive', $to->modify('+1 day')->format('Y-m-d'))
            ->orderBy('marketplace_sku');
    }

    /** @param list<string> $skus */
    public function observations(
        string $companyId,
        string $accountId,
        array $skus,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $axisColumn,
        int $limit,
        ?string $cursorSku,
        ?string $cursorRowId,
        ?string $cursorKey,
    ): QueryBuilder {
        $this->assertAxis($axisColumn);
        $unitCte = PlanningUnitOutcomeSql::cte('source_row_id IN (SELECT source_row_id FROM eligible_rows)');
        $eligible = $this->eligibleRowsSql($axisColumn);
        $cursorFilter = null === $cursorSku ? '' : 'AND (b.marketplace_sku, b.source_row_id, b.allocation_key) > (:cursorSku, :cursorRowId, :cursorKey)';
        $source = <<<SQL
            (
                WITH {$eligible}, {$unitCte}
                SELECT b.marketplace_sku, b.source_row_id, b.allocation_key, b.outcome, b.quantity,
                       o.first_known_outcome_at::text, o.first_regularly_observed_at::text,
                       o.source_event_at::text, o.backfill, o.raw_document_id::text
                FROM unit_outcome b
                JOIN planning_ingestion_resolution_observation o
                  ON o.company_id = b.company_id AND o.marketplace_account_id = b.marketplace_account_id
                 AND o.source_row_id = b.source_row_id AND o.allocation_key = b.allocation_key AND o.outcome = b.outcome
                WHERE b.marketplace_sku IN (:skus)
                  AND o.{$axisColumn} >= :from AND o.{$axisColumn} < :toExclusive
                  {$cursorFilter}
            ) resolution_observations
            SQL;
        $query = $this->connection->createQueryBuilder()->select('*')->from($source)
            ->setParameter('company', $companyId)->setParameter('account', $accountId)
            ->setParameter('skus', $skus, ArrayParameterType::STRING)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('toExclusive', $to->modify('+1 day')->format('Y-m-d'))
            ->orderBy('marketplace_sku')->addOrderBy('source_row_id')->addOrderBy('allocation_key')
            ->setMaxResults($limit);
        if (null !== $cursorSku) {
            $query->setParameter('cursorSku', $cursorSku)
                ->setParameter('cursorRowId', $cursorRowId)
                ->setParameter('cursorKey', $cursorKey);
        }

        return $query;
    }

    private function assertAxis(string $axisColumn): void
    {
        if (!\in_array($axisColumn, ['first_known_outcome_at', 'first_regularly_observed_at'], true)) {
            throw new \InvalidArgumentException('Unknown Planning observation date axis.');
        }
    }

    private function eligibleRowsSql(string $axisColumn): string
    {
        return <<<SQL
            eligible_rows AS MATERIALIZED (
                SELECT DISTINCT s.source_row_id
                FROM planning_ingestion_resolution_observation o
                JOIN sales_fact s
                  ON s.company_id = o.company_id AND s.marketplace_account_id = o.marketplace_account_id
                 AND s.source_row_id = o.source_row_id
                WHERE o.company_id = :company AND o.marketplace_account_id = :account
                  AND o.{$axisColumn} >= :from AND o.{$axisColumn} < :toExclusive
                  AND s.marketplace_sku IN (:skus)
            )
            SQL;
    }
}
