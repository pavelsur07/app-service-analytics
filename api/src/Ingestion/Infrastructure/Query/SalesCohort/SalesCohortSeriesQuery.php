<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\SalesCohort;

use App\Ingestion\Infrastructure\Query\Buyout\BuyoutForecastQuery;
use App\Ingestion\Infrastructure\Query\Buyout\BuyoutMaturityQuery;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/** Dense bounded aggregates. All arithmetic, including hidden history, stays in SQL. */
final readonly class SalesCohortSeriesQuery
{
    public function __construct(
        private Connection $connection,
        private SalesCohortMetricsQuery $metrics,
        private SalesCohortCoverageQuery $coverage,
    ) {
    }

    /** @param non-empty-list<string> $skus */
    public function build(
        string $companyId,
        string $accountId,
        array $skus,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $accumulationFrom,
        string $groupBy,
        ?string $currency,
        \DateTimeImmutable $calculatedAt,
        ?int $p95Seconds,
    ): QueryBuilder {
        if (!\in_array($groupBy, ['day', 'week', 'month'], true)) {
            throw new \InvalidArgumentException('Unknown cohort grouping.');
        }
        $metrics = $this->metrics->build($companyId, $accountId, $skus, $accumulationFrom, $to, $currency, $calculatedAt);
        $coverage = $this->coverage->build($companyId, $accountId, $accumulationFrom, $to);
        // SKU/day JSON is bounded by the underlying query (50 × 184). Unwrap
        // in PostgreSQL, never accumulate rounded daily projections in PHP.
        $sql = <<<'SQL'
            WITH metrics AS (%s), coverage AS (%s),
            loaded AS (
                SELECT marketplace_sku, d.*
                FROM metrics CROSS JOIN LATERAL jsonb_to_recordset(days::jsonb) AS d(
                    cohort_date date, raw_ordered_quantity bigint,
                    pre_handover_cancelled_quantity bigint, effective_ordered_quantity bigint,
                    observed_net_sales_quantity bigint, in_flight_quantity bigint,
                    buyout_resolved_quantity bigint, unestimated_quantity bigint, unknown_outcome_quantity bigint,
                    known_projected_buyout_quantity numeric, known_projected_eligible_quantity numeric,
                    observed_revenue_minor bigint
                )
            ), dense AS (
                SELECT s.sku, c.cohort_date::date AS day, c.orders_coverage,
                       COALESCE(l.raw_ordered_quantity, 0) AS raw_ordered_quantity,
                       COALESCE(l.pre_handover_cancelled_quantity, 0) AS pre_handover_cancelled_quantity,
                       COALESCE(l.effective_ordered_quantity, 0) AS effective_ordered_quantity,
                       COALESCE(l.observed_net_sales_quantity, 0) AS observed_net_sales_quantity,
                       COALESCE(l.in_flight_quantity, 0) AS in_flight_quantity,
                       COALESCE(l.buyout_resolved_quantity, 0) AS buyout_resolved_quantity,
                       COALESCE(l.unestimated_quantity, 0) AS unestimated_quantity,
                       COALESCE(l.unknown_outcome_quantity, 0) AS unknown_outcome_quantity,
                       COALESCE(l.known_projected_buyout_quantity, 0::numeric) AS projected_quantity,
                       COALESCE(l.known_projected_eligible_quantity, 0::numeric) AS projected_eligible_quantity,
                       CASE WHEN CAST(:currency AS text) IS NULL THEN NULL
                            WHEN l.marketplace_sku IS NULL THEN 0
                            ELSE l.observed_revenue_minor END AS observed_revenue_minor
                FROM jsonb_array_elements_text(CAST(:denseSkus AS jsonb)) s(sku) CROSS JOIN coverage c
                LEFT JOIN loaded l ON l.marketplace_sku = s.sku AND l.cohort_date = c.cohort_date::date
            ), buckets AS (
                SELECT GREATEST(MIN(day), CAST(:visibleFrom AS date)) AS bucket_from,
                       MAX(day) AS bucket_to
                FROM dense
                WHERE day >= :visibleFrom
                GROUP BY date_trunc(:groupBy, day::timestamp)
            ), aggregates AS (
                SELECT d.sku, b.bucket_from, b.bucket_to, scope.kind,
                       CASE WHEN BOOL_AND(d.orders_coverage = 'complete') THEN 'complete'
                            WHEN BOOL_AND(d.orders_coverage = 'missing') THEN 'missing'
                            ELSE 'partial' END AS orders_coverage,
                       SUM(d.raw_ordered_quantity)::bigint AS raw_ordered_quantity,
                       SUM(d.pre_handover_cancelled_quantity)::bigint AS pre_handover_cancelled_quantity,
                       SUM(d.effective_ordered_quantity)::bigint AS effective_ordered_quantity,
                       SUM(d.observed_net_sales_quantity)::bigint AS observed_net_sales_quantity,
                       SUM(d.in_flight_quantity)::bigint AS in_flight_quantity,
                       SUM(d.buyout_resolved_quantity)::bigint AS buyout_resolved_quantity,
                       SUM(d.unestimated_quantity)::bigint AS unestimated_quantity,
                       SUM(d.unknown_outcome_quantity)::bigint AS unknown_outcome_quantity,
                       SUM(d.projected_quantity) AS projected_quantity,
                       SUM(d.projected_eligible_quantity) AS projected_eligible_quantity,
                       CASE WHEN BOOL_AND(d.observed_revenue_minor IS NOT NULL)
                            THEN SUM(d.observed_revenue_minor)::bigint ELSE NULL END AS observed_revenue_minor,
                       COALESCE(CAST(:p95Seconds AS bigint) IS NOT NULL AND EXTRACT(EPOCH FROM (
                           CAST(:asOf AS timestamp) - ((b.bucket_to + 1)::timestamp AT TIME ZONE 'Europe/Moscow' AT TIME ZONE 'UTC')
                       )) > CAST(:p95Seconds AS bigint) AND
                           10000::numeric * SUM(d.in_flight_quantity) <= %d::numeric * SUM(d.raw_ordered_quantity), FALSE) AS mature
                FROM buckets b CROSS JOIN (VALUES ('period'), ('cumulative')) scope(kind)
                JOIN dense d ON d.day <= b.bucket_to
                    AND d.day >= CASE WHEN scope.kind = 'period' THEN b.bucket_from ELSE CAST(:from AS date) END
                GROUP BY d.sku, b.bucket_from, b.bucket_to, scope.kind
            ), components AS (
                SELECT *, %s AS value FROM aggregates
            ), points AS (
                SELECT sku, bucket_from, bucket_to,
                       jsonb_build_object(
                           'bucket_from', to_char(bucket_from, 'YYYY-MM-DD'),
                           'bucket_to', to_char(bucket_to, 'YYYY-MM-DD'),
                           'period_coverage', MAX(orders_coverage) FILTER (WHERE kind = 'period'),
                           'cumulative_coverage', MAX(orders_coverage) FILTER (WHERE kind = 'cumulative'),
                           'period', (jsonb_agg(value) FILTER (WHERE kind = 'period'))->0,
                           'cumulative', (jsonb_agg(value) FILTER (WHERE kind = 'cumulative'))->0
                       ) AS point
                FROM components GROUP BY sku, bucket_from, bucket_to
            )
            SELECT sku AS marketplace_sku, jsonb_agg(point ORDER BY bucket_from)::text AS points
            FROM points GROUP BY sku ORDER BY sku
            SQL;
        $quantity = BuyoutForecastQuery::projectedQuantitySql('projected_quantity', 'raw_ordered_quantity', 'unestimated_quantity');
        $rate = BuyoutForecastQuery::projectedRateSql('projected_quantity', 'projected_eligible_quantity', 'raw_ordered_quantity', 'unestimated_quantity');
        $unestimatedRate = BuyoutForecastQuery::unestimatedRateSql('unestimated_quantity', 'raw_ordered_quantity');
        $componentSql = <<<SQL
            CASE WHEN orders_coverage = 'complete' THEN jsonb_build_object(
                'cohort_date', to_char(bucket_to, 'YYYY-MM-DD'),
                'raw_ordered_quantity', raw_ordered_quantity,
                'pre_handover_cancelled_quantity', pre_handover_cancelled_quantity,
                'effective_ordered_quantity', effective_ordered_quantity,
                'observed_net_sales_quantity', observed_net_sales_quantity,
                'observed_revenue_minor', observed_revenue_minor,
                'valuation_currency', CAST(:currency AS text),
                'in_flight_quantity', in_flight_quantity,
                'buyout_resolved_quantity', buyout_resolved_quantity,
                'unestimated_quantity', unestimated_quantity,
                'unknown_outcome_quantity', unknown_outcome_quantity,
                'known_projected_buyout_quantity', projected_quantity::text,
                'known_projected_eligible_quantity', projected_eligible_quantity::text,
                'maturity_status', CASE WHEN mature THEN 'mature' ELSE 'preliminary' END,
                'in_flight_rate_bps', ROUND(10000::numeric * in_flight_quantity / NULLIF(raw_ordered_quantity, 0))::int,
                'known_buyout_rate_bps', ROUND(10000::numeric * observed_net_sales_quantity / NULLIF(buyout_resolved_quantity, 0))::int,
                'actual_buyout_rate_bps', CASE WHEN mature THEN ROUND(10000::numeric * observed_net_sales_quantity / NULLIF(buyout_resolved_quantity, 0))::int END,
                'current_projected_buyout_quantity', {$quantity},
                'current_projected_buyout_rate_bps', {$rate},
                'unestimated_rate_bps', {$unestimatedRate}
            ) ELSE NULL END
            SQL;
        // The inner IN uses DBAL array expansion; the dense grid receives
        // its own JSON binding, so SKU strings never become SQL text.
        $source = \sprintf($sql, $metrics->getSQL(), $coverage->getSQL(), BuyoutMaturityQuery::MAX_IN_FLIGHT_BPS, $componentSql);
        $query = $this->connection->createQueryBuilder()->select('series.*')->from('('.$source.')', 'series')->setMaxResults(50);
        foreach ($metrics->getParameters() as $name => $value) {
            $query->setParameter($name, $value, $metrics->getParameterType($name));
        }
        foreach ($coverage->getParameters() as $name => $value) {
            $query->setParameter($name, $value, $coverage->getParameterType($name));
        }

        return $query
            ->setParameter('denseSkus', json_encode(array_values(array_unique($skus)), \JSON_THROW_ON_ERROR))
            ->setParameter('visibleFrom', $from->format('Y-m-d'))
            ->setParameter('groupBy', $groupBy)
            ->setParameter('p95Seconds', $p95Seconds);
    }

    /** @param array<string, mixed> $row */
    public static function mapRow(array $row): SalesCohortSeriesRow
    {
        $sku = $row['marketplace_sku'] ?? null;
        $json = $row['points'] ?? null;
        if (!\is_string($sku) || !\is_string($json)) {
            throw new \UnexpectedValueException('Invalid sales cohort series row.');
        }
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($data) || !array_is_list($data) || \count($data) > 184) {
            throw new \UnexpectedValueException('Invalid sales cohort series.');
        }
        $points = [];
        foreach ($data as $point) {
            if (!\is_array($point) || !\is_string($point['bucket_from'] ?? null) || !\is_string($point['bucket_to'] ?? null)
                || !\in_array($point['period_coverage'] ?? null, ['complete', 'partial', 'missing'], true)
                || !\in_array($point['cumulative_coverage'] ?? null, ['complete', 'partial', 'missing'], true)) {
                throw new \UnexpectedValueException('Invalid sales cohort point.');
            }
            $period = $point['period'] ?? null;
            $cumulative = $point['cumulative'] ?? null;
            if ((null !== $period && !\is_array($period)) || (null !== $cumulative && !\is_array($cumulative))) {
                throw new \UnexpectedValueException('Invalid sales cohort components.');
            }
            $points[] = new SalesCohortSeriesPointRow(
                $point['bucket_from'], $point['bucket_to'], $point['period_coverage'], $point['cumulative_coverage'],
                null === $period ? null : SalesCohortMetricsQuery::mapDay($sku, $period),
                null === $cumulative ? null : SalesCohortMetricsQuery::mapDay($sku, $cumulative),
            );
        }

        return new SalesCohortSeriesRow($sku, $points);
    }
}
