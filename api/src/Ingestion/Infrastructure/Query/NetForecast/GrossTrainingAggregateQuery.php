<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class GrossTrainingAggregateQuery
{
    public function __construct(private Connection $connection, private NetTrainingLimits $limits = new NetTrainingLimits())
    {
    }

    public function build(string $companyId, string $accountId, NetTrainingContext $context, NetTrainingRequest $request): QueryBuilder
    {
        $context->assertScope($companyId, $accountId);
        $ctes = NetTrainingSourceSql::ctes(false).', '.NetTrainingOutcomeSql::ctes().', '.self::populationCtes();
        $source = <<<SQL
            WITH {$ctes}
            SELECT scope.marketplace_sku,
                   budget.ok AND maturity.p95_seconds IS NOT NULL AS input_complete,
                   CASE WHEN NOT budget.ok THEN 'input_budget_exceeded' WHEN maturity.p95_seconds IS NULL THEN 'maturity_unavailable' ELSE NULL END AS unavailable_reason,
                   CASE WHEN budget.ok AND maturity.p95_seconds IS NOT NULL THEN COALESCE(a.sample_quantity, 0) ELSE NULL END AS sample_quantity,
                   CASE WHEN budget.ok AND maturity.p95_seconds IS NOT NULL THEN COALESCE(a.sample_postings, 0) ELSE NULL END AS sample_postings,
                   CASE WHEN budget.ok AND maturity.p95_seconds IS NOT NULL THEN COALESCE(a.gross_quantity, 0) ELSE NULL END AS gross_quantity,
                   CASE WHEN budget.ok AND maturity.p95_seconds IS NOT NULL THEN COALESCE(a.t1_quantity, 0) ELSE NULL END AS t1_quantity,
                   CASE WHEN budget.ok AND maturity.p95_seconds IS NOT NULL THEN COALESCE(a.t2_quantity, 0) ELSE NULL END AS t2_quantity,
                   CASE WHEN budget.ok AND maturity.p95_seconds IS NOT NULL THEN COALESCE(a.p_quantity, 0) ELSE NULL END AS p_quantity,
                   CASE WHEN budget.ok AND maturity.p95_seconds IS NOT NULL THEN COALESCE(a.post_handover_postings, 0) ELSE NULL END AS post_handover_postings,
                   maturity.p95_seconds, budget.input AS budget_input, budget.observed AS budget_observed, budget.maximum AS budget_limit,
                   (SELECT COUNT(order_key)::int FROM candidate_groups g JOIN gross_days d USING (business_date) WHERE NOT d.eligible) AS excluded_groups,
                   (SELECT COUNT(business_date)::int FROM gross_days WHERE NOT eligible) AS excluded_days
            FROM (SELECT NULL::text AS marketplace_sku UNION ALL SELECT UNNEST(ARRAY[:requestedSkus]::text[])) scope
            CROSS JOIN input_budget budget CROSS JOIN gross_maturity maturity
            LEFT JOIN gross_aggregates a ON a.marketplace_sku IS NOT DISTINCT FROM scope.marketplace_sku
            ORDER BY scope.marketplace_sku NULLS FIRST
            LIMIT 51
            SQL;
        $query = $this->connection->createQueryBuilder()->select('result.marketplace_sku', 'result.input_complete', 'result.unavailable_reason', 'result.sample_quantity', 'result.sample_postings', 'result.gross_quantity', 'result.t1_quantity', 'result.t2_quantity', 'result.p_quantity', 'result.post_handover_postings', 'result.p95_seconds', 'result.excluded_groups', 'result.excluded_days', 'result.budget_input', 'result.budget_observed', 'result.budget_limit')->from('('.$source.')', 'result');

        return NetTrainingSourceSql::bind($query, $context, $request, $this->limits);
    }

    public static function populationCtes(): string
    {
        return <<<'SQL'
            maturity_postings AS MATERIALIZED (
                SELECT p.posting_number, GREATEST(0, EXTRACT(EPOCH FROM (p.first_terminal - ((MIN(r.business_date) + 1)::timestamp AT TIME ZONE 'Europe/Moscow' AT TIME ZONE 'UTC'))))::bigint AS seconds
                FROM posting_proof p JOIN training_sales r ON r.posting_number = p.posting_number
                JOIN source_proof sp ON sp.source_row_id = r.source_row_id
                WHERE p.valid AND p.terminal_observed AND sp.history_valid AND sp.postings_complete
                  AND p.posting_number IN (SELECT c.posting_number FROM canonical_outcomes c WHERE c.outcome IS NOT NULL)
                  AND r.business_date >= CAST(:grossFrom AS date) AND r.business_date < CAST(:grossTo AS date)
                GROUP BY p.posting_number, p.first_terminal
            ), gross_maturity AS MATERIALIZED (
                SELECT CASE WHEN COUNT(posting_number) >= 30 THEN PERCENTILE_DISC(0.95) WITHIN GROUP (ORDER BY seconds) ELSE NULL END AS p95_seconds
                FROM maturity_postings
            ), gross_return_balances AS MATERIALIZED (
                SELECT s.order_number, s.marketplace_sku, SUM(s.quantity::bigint) AS quantity
                FROM training_sales s JOIN latest_status l ON l.company_id = s.company_id AND l.marketplace_account_id = s.marketplace_account_id AND l.posting_number = s.posting_number
                WHERE l.status = 'delivered' AND l.substatus IN ('posting_delivered', 'posting_received') GROUP BY s.order_number, s.marketplace_sku
                UNION ALL
                SELECT r.order_number, r.marketplace_sku, -SUM(r.quantity::bigint) AS quantity FROM training_returns r
                WHERE r.return_type = 'ClientReturn' GROUP BY r.order_number, r.marketplace_sku
            ), over_returns AS MATERIALIZED (
                SELECT order_number, marketplace_sku FROM gross_return_balances
                GROUP BY order_number, marketplace_sku HAVING SUM(quantity) < 0
            ), gross_source_days AS MATERIALIZED (
                SELECT sp.business_date,
                       BOOL_AND(sp.valid)
                         AND NOT EXISTS(SELECT 1 FROM source_alias_proof bad JOIN over_returns o ON o.order_number = bad.order_number AND o.marketplace_sku = bad.marketplace_sku WHERE bad.business_date = sp.business_date) AS valid
                FROM source_alias_proof sp
                WHERE sp.business_date >= CAST(:grossFrom AS date) AND sp.business_date < CAST(:grossTo AS date)
                GROUP BY sp.business_date
            ), gross_outcome_days AS MATERIALIZED (
                SELECT business_date, COALESCE(SUM(quantity::bigint) FILTER (WHERE outcome IS NULL), 0) AS unknown_quantity
                FROM canonical_outcomes
                GROUP BY business_date
            ), gross_days AS MATERIALIZED (
                SELECT d.business_date, d.valid AND COALESCE(o.unknown_quantity = 0, FALSE)
                   AND m.p95_seconds IS NOT NULL
                   AND EXTRACT(EPOCH FROM (CAST(:asOf AS timestamp) - ((d.business_date + 1)::timestamp AT TIME ZONE 'Europe/Moscow' AT TIME ZONE 'UTC'))) > m.p95_seconds AS eligible
                FROM gross_source_days d LEFT JOIN gross_outcome_days o USING (business_date) CROSS JOIN gross_maturity m
            ), gross_aggregates AS MATERIALIZED (
                SELECT CASE WHEN GROUPING(c.marketplace_sku) = 1 THEN NULL ELSE c.marketplace_sku END AS marketplace_sku,
                       SUM(c.quantity::bigint) AS sample_quantity,
                       COUNT(DISTINCT c.posting_number) AS sample_postings,
                       COALESCE(SUM(c.quantity::bigint) FILTER (WHERE c.outcome IN ('D', 'R')), 0) AS gross_quantity,
                       COALESCE(SUM(c.quantity::bigint) FILTER (WHERE c.outcome = 'T1'), 0) AS t1_quantity,
                       COALESCE(SUM(c.quantity::bigint) FILTER (WHERE c.outcome = 'T2'), 0) AS t2_quantity,
                       COALESCE(SUM(c.quantity::bigint) FILTER (WHERE c.outcome = 'P'), 0) AS p_quantity,
                       COUNT(DISTINCT c.posting_number) FILTER (WHERE c.outcome IN ('D', 'R', 'T2', 'P')) AS post_handover_postings
                FROM canonical_outcomes c JOIN gross_days d USING (business_date) CROSS JOIN input_budget b
                WHERE d.eligible AND b.ok
                GROUP BY GROUPING SETS ((), (c.marketplace_sku))
            )
            SQL;
    }

    /** @param array<string, mixed> $row */
    public static function mapRow(array $row): GrossTrainingAggregateRow
    {
        return new GrossTrainingAggregateRow(NetTrainingValue::nullableString($row['marketplace_sku']), NetTrainingValue::boolean($row['input_complete']), NetTrainingValue::nullableString($row['unavailable_reason']), NetTrainingValue::nullableInteger($row['sample_quantity']), NetTrainingValue::nullableInteger($row['sample_postings']), NetTrainingValue::nullableInteger($row['gross_quantity']), NetTrainingValue::nullableInteger($row['t1_quantity']), NetTrainingValue::nullableInteger($row['t2_quantity']), NetTrainingValue::nullableInteger($row['p_quantity']), NetTrainingValue::nullableInteger($row['post_handover_postings']), NetTrainingValue::nullableInteger($row['p95_seconds']), NetTrainingValue::integer($row['excluded_groups']), NetTrainingValue::integer($row['excluded_days']), NetTrainingValue::budgetFailure($row));
    }
}
