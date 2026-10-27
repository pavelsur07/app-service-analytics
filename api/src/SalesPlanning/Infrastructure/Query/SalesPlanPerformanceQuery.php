<?php

declare(strict_types=1);

namespace App\SalesPlanning\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/** Original goal only; requested revision contributes SKU membership, never numeric values. */
final readonly class SalesPlanPerformanceQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function build(string $companyId, string $planId, string $baselineId, string $from, string $to, string $start, string $group, ?string $cursor, int $limit): QueryBuilder
    {
        if ($limit < 1 || $limit > 50 || !\in_array($group, ['day', 'week', 'month', 'period'], true)) {
            throw new \InvalidArgumentException('Invalid performance page.');
        }
        $dates = [];
        foreach ([$start, $from, $to] as $value) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
            if (false === $date || $date->format('Y-m-d') !== $value) {
                throw new \InvalidArgumentException('Invalid performance date.');
            }
            $dates[] = $date;
        }
        if ($dates[0] > $dates[1] || $dates[1] > $dates[2] || $dates[0]->diff($dates[2])->days >= 184) {
            throw new \InvalidArgumentException('Performance range must contain 1..184 days.');
        }
        $sql = <<<'SQL'
            WITH page AS (
                SELECT DISTINCT marketplace_sku FROM sales_plan_line
                WHERE company_id = :companyId AND sales_plan_id IN (:planId, :baselineId)
                  AND (CAST(:cursor AS text) IS NULL OR marketplace_sku > :cursor)
                ORDER BY marketplace_sku LIMIT :pageLimit
            ), dense AS (
                SELECT s.marketplace_sku, d.day::date AS day,
                       COALESCE(l.quantity, 0)::bigint AS quantity,
                       COALESCE(l.quantity::bigint * l.price_minor, 0)::bigint AS revenue_minor
                FROM page s CROSS JOIN generate_series(CAST(:start AS timestamp), CAST(:to AS timestamp), INTERVAL '1 day') d(day)
                LEFT JOIN sales_plan_line l ON l.company_id = :companyId AND l.sales_plan_id = :baselineId
                    AND l.marketplace_sku = s.marketplace_sku AND l.plan_date = d.day::date
            ), running AS (
                SELECT *, SUM(quantity) OVER w AS cumulative_quantity, SUM(revenue_minor) OVER w AS cumulative_revenue_minor
                FROM dense WINDOW w AS (PARTITION BY marketplace_sku ORDER BY day ROWS UNBOUNDED PRECEDING)
            ), buckets AS (
                SELECT marketplace_sku, MIN(day) AS bucket_from, MAX(day) AS bucket_to,
                       SUM(quantity)::bigint AS period_quantity, SUM(revenue_minor)::bigint AS period_revenue_minor,
                       MAX(cumulative_quantity)::bigint AS cumulative_quantity,
                       MAX(cumulative_revenue_minor)::bigint AS cumulative_revenue_minor
                FROM running WHERE day >= CAST(:from AS date)
                GROUP BY marketplace_sku, CASE WHEN :groupBy = 'period' THEN CAST(:from AS timestamp) ELSE date_trunc(:groupBy, day::timestamp) END
            )
            SELECT marketplace_sku, jsonb_agg(jsonb_build_object(
                'bucket_from', to_char(bucket_from, 'YYYY-MM-DD'), 'bucket_to', to_char(bucket_to, 'YYYY-MM-DD'),
                'period_quantity', period_quantity, 'period_revenue_minor', period_revenue_minor,
                'cumulative_quantity', cumulative_quantity, 'cumulative_revenue_minor', cumulative_revenue_minor
            ) ORDER BY bucket_from)::text AS points FROM buckets GROUP BY marketplace_sku ORDER BY marketplace_sku
            SQL;

        return $this->connection->createQueryBuilder()->select('p.*')->from('('.$sql.')', 'p')
            ->setParameter('companyId', $companyId)->setParameter('planId', $planId)->setParameter('baselineId', $baselineId)
            ->setParameter('cursor', $cursor)->setParameter('pageLimit', $limit + 1, \Doctrine\DBAL\ParameterType::INTEGER)
            ->setParameter('start', $start)->setParameter('from', $from)->setParameter('to', $to)->setParameter('groupBy', $group)
            ->setMaxResults($limit + 1);
    }

    /** @param array<string, mixed> $row */
    public static function mapRow(array $row): SalesPlanPerformanceSkuRow
    {
        $sku = $row['marketplace_sku'] ?? null;
        $json = $row['points'] ?? null;
        if (!\is_string($sku) || !\is_string($json)) {
            throw new \UnexpectedValueException('Invalid performance plan row.');
        }
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($data) || !array_is_list($data) || \count($data) > 184) {
            throw new \UnexpectedValueException('Invalid performance plan series.');
        }
        $points = [];
        foreach ($data as $point) {
            if (!\is_array($point) || !\is_string($point['bucket_from'] ?? null) || !\is_string($point['bucket_to'] ?? null)) {
                throw new \UnexpectedValueException('Invalid performance plan point.');
            }
            foreach (['period_quantity', 'period_revenue_minor', 'cumulative_quantity', 'cumulative_revenue_minor'] as $key) {
                if (!\is_int($point[$key] ?? null)) {
                    throw new \UnexpectedValueException('Invalid performance plan amount.');
                }
            }
            $points[] = new SalesPlanPerformancePointRow($point['bucket_from'], $point['bucket_to'], $point['period_quantity'], $point['period_revenue_minor'], $point['cumulative_quantity'], $point['cumulative_revenue_minor']);
        }

        return new SalesPlanPerformanceSkuRow($sku, $points);
    }
}
