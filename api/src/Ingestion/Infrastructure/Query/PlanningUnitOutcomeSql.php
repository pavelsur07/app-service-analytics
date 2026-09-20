<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

/** Deterministic unit positions within one source row; outcomes can change without changing the position. */
final class PlanningUnitOutcomeSql
{
    /** $orderPredicate must be a fixed SQL fragment supplied by the writer, never user input. */
    public static function cte(?string $orderPredicate = null): string
    {
        $orderFilter = null === $orderPredicate ? '' : "AND ({$orderPredicate})";

        return <<<SQL
            tenant_outcome AS MATERIALIZED (
                SELECT company_id, marketplace_account_id, source_row_id, order_number,
                       marketplace_sku, outcome, quantity
                FROM buyout_outcome
                WHERE company_id = :company AND marketplace_account_id = :account
                  {$orderFilter}
            ), numbered_outcome AS (
                SELECT b.*,
                       COALESCE(SUM(b.quantity) OVER (
                           PARTITION BY b.company_id, b.marketplace_account_id, b.source_row_id
                           ORDER BY CASE b.outcome
                               WHEN 'D' THEN 1 WHEN 'R' THEN 2 WHEN 'T1' THEN 3
                               WHEN 'T2' THEN 4 WHEN 'P' THEN 5 ELSE 6 END
                           ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING
                       ), 0) AS preceding_quantity
                FROM tenant_outcome b
                WHERE b.outcome IS NOT NULL
            ), unit_outcome AS (
                SELECT b.company_id, b.marketplace_account_id, b.source_row_id,
                       b.order_number, b.marketplace_sku, b.outcome,
                       (b.preceding_quantity + unit.position)::text AS allocation_key,
                       unit.position AS outcome_position,
                       1::int AS quantity
                FROM numbered_outcome b
                CROSS JOIN LATERAL generate_series(1, b.quantity) AS unit(position)
            )
            SQL;
    }
}
