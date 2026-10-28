<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class ReturnTrainingAggregateQuery
{
    public function __construct(private Connection $connection, private NetTrainingLimits $limits = new NetTrainingLimits())
    {
    }

    public function build(string $companyId, string $accountId, NetTrainingContext $context, NetTrainingRequest $request): QueryBuilder
    {
        $context->assertScope($companyId, $accountId);
        $ctes = NetTrainingSourceSql::ctes(true).', '.self::populationCtes();
        $source = <<<SQL
            WITH {$ctes}
            SELECT scope.marketplace_sku, budget.ok AS input_complete,
                   CASE WHEN budget.ok THEN NULL ELSE 'input_budget_exceeded' END AS unavailable_reason,
                   CASE WHEN budget.ok THEN COALESCE(sample.quantity, 0) ELSE NULL END AS sample_quantity,
                   CASE WHEN budget.ok THEN COALESCE(sample.postings, 0) ELSE NULL END AS sample_postings,
                   CASE WHEN budget.ok THEN profiles.age_profile ELSE NULL END AS age_profile,
                   budget.input AS budget_input, budget.observed AS budget_observed, budget.maximum AS budget_limit,
                   (SELECT COUNT(marketplace_sku)::int FROM return_groups WHERE NOT eligible) AS excluded_groups
            FROM (SELECT NULL::text AS marketplace_sku UNION ALL SELECT UNNEST(ARRAY[:requestedSkus]::text[])) scope
            CROSS JOIN input_budget budget
            LEFT JOIN return_samples sample ON sample.marketplace_sku IS NOT DISTINCT FROM scope.marketplace_sku
            LEFT JOIN return_profiles profiles ON profiles.marketplace_sku IS NOT DISTINCT FROM scope.marketplace_sku
            ORDER BY scope.marketplace_sku NULLS FIRST LIMIT 51
            SQL;
        $query = $this->connection->createQueryBuilder()->select('result.marketplace_sku', 'result.input_complete', 'result.unavailable_reason', 'result.sample_quantity', 'result.sample_postings', 'result.age_profile', 'result.excluded_groups', 'result.budget_input', 'result.budget_observed', 'result.budget_limit')->from('('.$source.')', 'result');

        return NetTrainingSourceSql::bind($query, $context, $request, $this->limits);
    }

    public static function populationCtes(): string
    {
        return <<<'SQL'
            delivery_anchors AS MATERIALIZED (
                SELECT posting_number, raw_document_id, status, known, anchored, observed_at,
                       MIN(observed_at) FILTER (WHERE status = 'delivered') OVER (PARTITION BY posting_number) AS first_delivery
                FROM status_anchors
            ), delivery_sources AS MATERIALIZED (
                SELECT DISTINCT ON (r.source_row_id) r.source_row_id, r.quantity AS gross_quantity, r.fact_revision AS delivery_revision
                FROM sales_revisions r JOIN delivery_anchors s ON s.posting_number = r.posting_number AND s.raw_document_id = r.raw_document_id AND s.status = 'delivered' AND s.known AND s.anchored AND s.first_delivery = s.observed_at
                ORDER BY r.source_row_id, r.fact_revision
            ), changed_delivery_quantity AS MATERIALIZED (
                SELECT DISTINCT r.source_row_id FROM sales_revisions r JOIN delivery_sources d ON d.source_row_id = r.source_row_id
                WHERE r.fact_revision > d.delivery_revision AND r.quantity <> d.gross_quantity
            ), first_source_states AS MATERIALIZED (
                SELECT source_row_id, status FROM sales_revisions WHERE fact_revision = 1
            ), return_members AS MATERIALIZED (
                SELECT k.source_row_id, k.order_number, k.marketplace_sku, k.posting_number, p.delivery_origin,
                       d.gross_quantity,
                       COALESCE(sp.history_valid, FALSE) AND COALESCE(sp.postings_complete, FALSE) AND COALESCE(p.terminal_observed, FALSE)
                          AND first.status IN ('awaiting_packaging', 'awaiting_deliver', 'delivering') AND changed.source_row_id IS NULL
                          AND (p.delivery_origin IS NULL OR d.source_row_id IS NOT NULL) AS valid
                FROM sale_keys k LEFT JOIN source_proof sp ON sp.source_row_id = k.source_row_id
                LEFT JOIN posting_proof p ON p.posting_number = k.posting_number
                LEFT JOIN delivery_sources d ON d.source_row_id = k.source_row_id
                LEFT JOIN changed_delivery_quantity changed ON changed.source_row_id = k.source_row_id
                LEFT JOIN first_source_states first ON first.source_row_id = k.source_row_id
            ), client_deltas AS MATERIALIZED (
                SELECT r.source_row_id, r.order_number, r.marketplace_sku, r.captured_at,
                       CASE WHEN r.return_type = 'ClientReturn' THEN CASE WHEN r.previous_type = 'ClientReturn' THEN r.quantity::bigint - r.previous_quantity ELSE r.quantity::bigint END ELSE 0::bigint END AS quantity
                FROM return_ordered r
            ), return_base_groups AS MATERIALIZED (
                SELECT m.order_number, m.marketplace_sku, MIN(m.delivery_origin) AS origin,
                       COALESCE(SUM(m.gross_quantity::bigint), 0) AS gross_quantity,
                       BOOL_AND(bad.marketplace_sku IS NULL) AS quantity_valid,
                       BOOL_AND(m.valid AND COALESCE(aliases.valid, FALSE)) AND MIN(m.delivery_origin) = MAX(m.delivery_origin) AND SUM(m.gross_quantity::bigint) > 0 AS source_valid
                FROM return_members m LEFT JOIN source_order_proof aliases USING (order_number, marketplace_sku)
                LEFT JOIN contradictory_quantity_groups bad USING (order_number, marketplace_sku)
                GROUP BY m.order_number, m.marketplace_sku
                HAVING BOOL_OR(m.delivery_origin >= CAST(:originFrom AS timestamp) AND m.delivery_origin < CAST(:originTo AS timestamp))
            ), calendar_dates AS MATERIALIZED (
                SELECT day::date AS day FROM generate_series(
                    ((CAST(:originFrom AS timestamp) AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Moscow')::date)::timestamp,
                    ((CAST(:asOf AS timestamp) AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Moscow')::date)::timestamp,
                    INTERVAL '1 day') day
            ), receipt_calendar AS MATERIALIZED (
                SELECT d.day,
                       MAX(r.marked_at) FILTER (WHERE r.status = 'completed' AND r.capture_origin = 'live_polling' AND r.capture_version = :captureVersion) AS latest_completed,
                       MAX(r.marked_at) FILTER (WHERE r.status = 'failed') AS latest_failed,
                       MAX(r.marked_at) FILTER (WHERE r.status = 'completed' AND r.capture_origin = 'live_polling' AND r.capture_version = :captureVersion
                         AND r.marked_at >= ((d.day + 1)::timestamp AT TIME ZONE 'Europe/Moscow' AT TIME ZONE 'UTC')
                         AND r.marked_at <= ((d.day + 2)::timestamp AT TIME ZONE 'Europe/Moscow' AT TIME ZONE 'UTC')) AS timely_completed,
                       MAX(r.marked_at) FILTER (WHERE r.status = 'failed' AND r.marked_at <= ((d.day + 2)::timestamp AT TIME ZONE 'Europe/Moscow' AT TIME ZONE 'UTC')) AS timely_failed
                FROM calendar_dates d CROSS JOIN input_budget budget
                LEFT JOIN receipt_inputs r ON r.report_type = 'ozon_returns_list' AND d.day BETWEEN r.date_from AND r.date_to
                WHERE budget.ok
                GROUP BY d.day
            ), required_daily_windows AS MATERIALIZED (
                SELECT DISTINCT (origin AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Moscow')::date AS day_from,
                       ((origin + INTERVAL '67 days') AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Moscow')::date AS day_to
                FROM return_base_groups
            ), daily_window_proof AS MATERIALIZED (
                SELECT w.day_from, w.day_to,
                       COUNT(c.day) = w.day_to - w.day_from + 1
                         AND BOOL_AND(c.timely_completed IS NOT NULL AND (c.timely_failed IS NULL OR c.timely_completed > c.timely_failed)
                           AND c.latest_completed IS NOT NULL AND (c.latest_failed IS NULL OR c.latest_completed > c.latest_failed)) AS valid
                FROM required_daily_windows w JOIN receipt_calendar c ON c.day BETWEEN w.day_from AND w.day_to
                GROUP BY w.day_from, w.day_to
            ), daily_group_proof AS MATERIALIZED (
                SELECT g.order_number, g.marketplace_sku, w.valid
                FROM return_base_groups g JOIN daily_window_proof w
                  ON w.day_from = (g.origin AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Moscow')::date
                 AND w.day_to = ((g.origin + INTERVAL '67 days') AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Moscow')::date
            ), deep_receipts AS MATERIALIZED (
                SELECT r.date_from, r.date_to, r.marked_at FROM receipt_inputs r
                WHERE r.report_type = 'ozon_returns_list' AND r.status = 'completed' AND r.capture_origin = 'live_polling' AND r.capture_version = :captureVersion AND r.date_to - r.date_from >= 60
            ), deep_group_proof AS MATERIALIZED (
                SELECT g.order_number, g.marketplace_sku, BOOL_OR(r.marked_at >= g.origin + INTERVAL '67 days') AS valid
                FROM return_base_groups g LEFT JOIN deep_receipts r
                  ON r.date_from <= (g.origin AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Moscow')::date
                 AND r.date_to >= ((g.origin + INTERVAL '60 days') AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Moscow')::date
                GROUP BY g.order_number, g.marketplace_sku
            ), delta_group_proof AS MATERIALIZED (
                SELECT g.order_number, g.marketplace_sku,
                       COALESCE(SUM(d.quantity) FILTER (WHERE d.quantity > 0), 0) <= g.gross_quantity
                           AND NOT COALESCE(BOOL_OR(d.quantity < 0 OR (d.quantity > 0 AND d.captured_at <= g.origin)), FALSE) AS valid
                FROM return_base_groups g LEFT JOIN client_deltas d ON d.order_number = g.order_number AND d.marketplace_sku = g.marketplace_sku
                GROUP BY g.order_number, g.marketplace_sku, g.gross_quantity
            ), return_groups AS MATERIALIZED (
                SELECT g.order_number, g.marketplace_sku, g.origin, g.gross_quantity,
                       g.source_valid AND g.quantity_valid AND COALESCE(daily.valid, FALSE) AND COALESCE(deep.valid, FALSE) AND COALESCE(delta.valid, FALSE) AS eligible,
                       CASE WHEN NOT g.source_valid THEN 'source_history_unavailable' WHEN NOT g.quantity_valid THEN 'contradictory_quantity' WHEN NOT COALESCE(daily.valid, FALSE) OR NOT COALESCE(deep.valid, FALSE) THEN 'returns_coverage_missing' WHEN NOT COALESCE(delta.valid, FALSE) THEN 'contradictory_quantity' ELSE NULL END AS exclusion_reason
                FROM return_base_groups g LEFT JOIN daily_group_proof daily USING (order_number, marketplace_sku)
                LEFT JOIN deep_group_proof deep USING (order_number, marketplace_sku)
                LEFT JOIN delta_group_proof delta USING (order_number, marketplace_sku)
            ), eligible_return_groups AS MATERIALIZED (
                SELECT order_number, marketplace_sku, origin, gross_quantity FROM return_groups WHERE eligible
            ), posting_capacities AS MATERIALIZED (
                SELECT m.order_number, m.marketplace_sku, m.posting_number, SUM(m.gross_quantity::bigint) AS capacity
                FROM return_members m JOIN eligible_return_groups g USING (order_number, marketplace_sku)
                WHERE m.gross_quantity IS NOT NULL
                GROUP BY m.order_number, m.marketplace_sku, m.posting_number
            ), capacity_prefixes AS MATERIALIZED (
                SELECT p.order_number, p.marketplace_sku, p.posting_number, p.capacity,
                       SUM(p.capacity) OVER (PARTITION BY p.order_number, p.marketplace_sku ORDER BY p.capacity DESC, p.posting_number ROWS UNBOUNDED PRECEDING) AS prefix_capacity
                FROM posting_capacities p
            ), client_delta_bins AS MATERIALIZED (
                SELECT d.order_number, d.marketplace_sku, FLOOR(EXTRACT(EPOCH FROM (d.captured_at - g.origin)) / 86400)::int AS bin, SUM(d.quantity) AS quantity
                FROM client_deltas d JOIN eligible_return_groups g USING (order_number, marketplace_sku)
                WHERE d.quantity > 0 AND d.captured_at > g.origin AND d.captured_at < g.origin + INTERVAL '60 days'
                GROUP BY d.order_number, d.marketplace_sku, FLOOR(EXTRACT(EPOCH FROM (d.captured_at - g.origin)) / 86400)::int
            ), order_event_boundaries AS MATERIALIZED (
                SELECT order_number, 0 AS age FROM eligible_return_groups
                UNION SELECT order_number, bin + 1 AS age FROM client_delta_bins
            ), group_at_breakpoints AS MATERIALIZED (
                SELECT g.order_number, g.marketplace_sku, a.age, g.gross_quantity, COALESCE(SUM(d.quantity), 0) AS returned_quantity,
                       g.gross_quantity - COALESCE(SUM(d.quantity), 0) AS at_risk_quantity
                FROM eligible_return_groups g JOIN order_event_boundaries a USING (order_number)
                LEFT JOIN client_delta_bins d ON d.order_number = g.order_number AND d.marketplace_sku = g.marketplace_sku AND d.bin < a.age
                GROUP BY g.order_number, g.marketplace_sku, a.age, g.gross_quantity
            ), group_minimum_postings AS MATERIALIZED (
                SELECT g.order_number, g.marketplace_sku, g.age,
                       CASE WHEN g.at_risk_quantity = 0 THEN 0 ELSE 1 + COUNT(p.posting_number) FILTER (WHERE p.prefix_capacity < g.at_risk_quantity) END AS lower_bound
                FROM group_at_breakpoints g JOIN capacity_prefixes p USING (order_number, marketplace_sku)
                GROUP BY g.order_number, g.marketplace_sku, g.age, g.at_risk_quantity
            ), order_guaranteed_postings AS MATERIALIZED (
                SELECT g.order_number, g.age, COUNT(DISTINCT p.posting_number) AS lower_bound
                FROM group_at_breakpoints g JOIN posting_capacities p ON p.order_number = g.order_number AND p.marketplace_sku = g.marketplace_sku AND p.capacity > g.returned_quantity
                GROUP BY g.order_number, g.age
            ), order_posting_bounds AS MATERIALIZED (
                SELECT g.order_number, g.age, GREATEST(MAX(g.lower_bound), COALESCE(MAX(p.lower_bound), 0)) AS lower_bound
                FROM group_minimum_postings g LEFT JOIN order_guaranteed_postings p USING (order_number, age)
                GROUP BY g.order_number, g.age
            ), posting_bound_deltas AS MATERIALIZED (
                SELECT NULL::text AS marketplace_sku, age, lower_bound - COALESCE(LAG(lower_bound) OVER (PARTITION BY order_number ORDER BY age), 0) AS delta FROM order_posting_bounds
                UNION ALL
                SELECT marketplace_sku, age, lower_bound - COALESCE(LAG(lower_bound) OVER (PARTITION BY order_number, marketplace_sku ORDER BY age), 0) AS delta FROM group_minimum_postings
            ), scope_posting_bounds AS MATERIALIZED (
                SELECT marketplace_sku, age, SUM(SUM(delta)) OVER (PARTITION BY marketplace_sku ORDER BY age) AS lower_bound
                FROM posting_bound_deltas GROUP BY marketplace_sku, age
            ), group_quantity_deltas AS MATERIALIZED (
                SELECT order_number, marketplace_sku, age,
                       returned_quantity - COALESCE(LAG(returned_quantity) OVER group_ages, 0) AS returned_delta,
                       at_risk_quantity - COALESCE(LAG(at_risk_quantity) OVER group_ages, 0) AS risk_delta
                FROM group_at_breakpoints WINDOW group_ages AS (PARTITION BY order_number, marketplace_sku ORDER BY age)
            ), scope_quantity_deltas AS MATERIALIZED (
                SELECT CASE WHEN GROUPING(marketplace_sku) = 1 THEN NULL ELSE marketplace_sku END AS marketplace_sku,
                       age, SUM(returned_delta) AS returned_delta, SUM(risk_delta) AS risk_delta
                FROM group_quantity_deltas GROUP BY GROUPING SETS ((age), (age, marketplace_sku))
            ), scope_breakpoints AS MATERIALIZED (
                SELECT marketplace_sku, age, SUM(returned_delta) OVER scope_ages AS returned_quantity,
                       SUM(risk_delta) OVER scope_ages AS at_risk_quantity
                FROM scope_quantity_deltas WINDOW scope_ages AS (PARTITION BY marketplace_sku ORDER BY age)
            ), expanded_profile AS MATERIALIZED (
                SELECT DISTINCT ON (scope.marketplace_sku, age.age) scope.marketplace_sku, age.age,
                       COALESCE(b.returned_quantity, 0) AS returned_quantity, COALESCE(b.at_risk_quantity, 0) AS at_risk_quantity,
                       COALESCE(p.lower_bound, 0) AS posting_lower_bound
                FROM (SELECT NULL::text AS marketplace_sku UNION ALL SELECT UNNEST(ARRAY[:requestedSkus]::text[])) scope CROSS JOIN generate_series(0, 60) age(age)
                LEFT JOIN scope_breakpoints b ON COALESCE('sku:' || b.marketplace_sku, 'account') = COALESCE('sku:' || scope.marketplace_sku, 'account') AND b.age <= age.age
                LEFT JOIN scope_posting_bounds p ON COALESCE('sku:' || p.marketplace_sku, 'account') = COALESCE('sku:' || b.marketplace_sku, 'account') AND p.age = b.age
                ORDER BY scope.marketplace_sku, age.age, b.age DESC
            ), return_profiles AS MATERIALIZED (
                SELECT marketplace_sku, JSONB_AGG(JSONB_BUILD_OBJECT('age', age, 'returnedQuantity', returned_quantity, 'atRiskQuantity', at_risk_quantity, 'atRiskPostingsLowerBound', posting_lower_bound) ORDER BY age) AS age_profile
                FROM expanded_profile GROUP BY marketplace_sku
            ), return_samples AS MATERIALIZED (
                SELECT CASE WHEN GROUPING(p.marketplace_sku) = 1 THEN NULL ELSE p.marketplace_sku END AS marketplace_sku,
                       SUM(p.capacity) AS quantity, COUNT(DISTINCT p.posting_number) AS postings
                FROM posting_capacities p GROUP BY GROUPING SETS ((), (p.marketplace_sku))
            )
            SQL;
    }

    /** @param array<string, mixed> $row */
    public static function mapRow(array $row): ReturnTrainingAggregateRow
    {
        $profile = null;
        if (null !== $row['age_profile']) {
            if (!\is_string($row['age_profile'])) {
                throw new \UnexpectedValueException('Expected JSON training age profile.');
            }
            $decoded = json_decode($row['age_profile'], true, 8, \JSON_THROW_ON_ERROR);
            if (!\is_array($decoded) || !array_is_list($decoded) || 61 !== \count($decoded)) {
                throw new \UnexpectedValueException('Training profile must have 61 age boundaries.');
            }
            $profile = [];
            foreach ($decoded as $age => $point) {
                if (!\is_array($point) || $age !== ($point['age'] ?? null)) {
                    throw new \UnexpectedValueException('Invalid training profile age.');
                }
                $profile[] = new ReturnTrainingAgeRow($age, NetTrainingValue::integer($point['returnedQuantity'] ?? null), NetTrainingValue::integer($point['atRiskQuantity'] ?? null), NetTrainingValue::integer($point['atRiskPostingsLowerBound'] ?? null));
            }
        }

        return new ReturnTrainingAggregateRow(NetTrainingValue::nullableString($row['marketplace_sku']), NetTrainingValue::boolean($row['input_complete']), NetTrainingValue::nullableString($row['unavailable_reason']), NetTrainingValue::nullableInteger($row['sample_quantity']), NetTrainingValue::nullableInteger($row['sample_postings']), $profile, NetTrainingValue::integer($row['excluded_groups']), budgetFailure: NetTrainingValue::budgetFailure($row));
    }
}
