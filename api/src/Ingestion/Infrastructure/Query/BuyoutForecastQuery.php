<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Live forecast ADR-019: mature 30-day baseline, SKU sample >=30 или
 * account fallback. Не материализуется и меняется сразу с status history.
 */
final readonly class BuyoutForecastQuery
{
    public const int MIN_TRAINING_QUANTITY = 30;

    public function __construct(private Connection $connection)
    {
    }

    public function build(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $asOf,
        int $limit,
        ?string $cursor,
    ): QueryBuilder {
        $maturitySample = BuyoutMaturityQuery::MIN_SAMPLE_SIZE;
        $trainingSample = self::MIN_TRAINING_QUANTITY;
        $source = <<<SQL
            WITH tenant_outcome AS MATERIALIZED (
                SELECT company_id, marketplace_account_id, source_row_id,
                       posting_number, order_number, marketplace_sku,
                       quantity, business_date, outcome,
                       handed_over_at, resolved_at, is_forecast_eligible
                FROM buyout_outcome
                WHERE company_id = :companyId
            ),
            posting_intervals AS (
                SELECT DISTINCT company_id, marketplace_account_id, posting_number,
                       EXTRACT(EPOCH FROM (resolved_at - handed_over_at))::bigint AS duration_seconds
                FROM tenant_outcome
                WHERE outcome IS NOT NULL
                  AND posting_number IS NOT NULL
                  AND handed_over_at IS NOT NULL
                  AND resolved_at IS NOT NULL
                  AND resolved_at >= handed_over_at
                  AND resolved_at <= :asOf
            ),
            maturity AS (
                SELECT marketplace_account_id,
                       CASE WHEN COUNT(*) >= {$maturitySample}
                            THEN PERCENTILE_DISC(0.95) WITHIN GROUP (ORDER BY duration_seconds)
                            ELSE NULL
                       END AS p95_seconds
                FROM posting_intervals
                GROUP BY marketplace_account_id
            ),
            training_rows AS (
                SELECT o.*
                FROM tenant_outcome o
                JOIN maturity m ON m.marketplace_account_id = o.marketplace_account_id
                WHERE m.p95_seconds IS NOT NULL
                  AND o.outcome IN ('T1', 'D', 'T2', 'P')
                  AND o.business_date < (:asOfMoscow::timestamp - make_interval(secs => m.p95_seconds::double precision))::date
                  AND o.business_date >= (:asOfMoscow::timestamp - make_interval(secs => m.p95_seconds::double precision))::date - INTERVAL '30 days'
                  AND EXTRACT(EPOCH FROM (
                      :asOf::timestamp - ((o.business_date + 1)::timestamp AT TIME ZONE 'Europe/Moscow' AT TIME ZONE 'UTC')
                  )) > m.p95_seconds
            ),
            sku_training AS (
                SELECT marketplace_account_id, marketplace_sku,
                       SUM(quantity)::bigint AS sample_quantity,
                       COALESCE(SUM(quantity) FILTER (WHERE outcome = 'D'), 0)::bigint AS d_quantity,
                       COALESCE(SUM(quantity) FILTER (WHERE outcome = 'T1'), 0)::bigint AS t1_quantity,
                       COALESCE(SUM(quantity) FILTER (WHERE outcome = 'T2'), 0)::bigint AS t2_quantity,
                       COALESCE(SUM(quantity) FILTER (WHERE outcome = 'P'), 0)::bigint AS p_quantity
                FROM training_rows
                GROUP BY marketplace_account_id, marketplace_sku
            ),
            account_training AS (
                SELECT marketplace_account_id,
                       SUM(quantity)::bigint AS sample_quantity,
                       COALESCE(SUM(quantity) FILTER (WHERE outcome = 'D'), 0)::bigint AS d_quantity,
                       COALESCE(SUM(quantity) FILTER (WHERE outcome = 'T1'), 0)::bigint AS t1_quantity,
                       COALESCE(SUM(quantity) FILTER (WHERE outcome = 'T2'), 0)::bigint AS t2_quantity,
                       COALESCE(SUM(quantity) FILTER (WHERE outcome = 'P'), 0)::bigint AS p_quantity
                FROM training_rows
                GROUP BY marketplace_account_id
            ),
            current_rows AS (
                SELECT o.*,
                       CASE
                           WHEN s.sample_quantity >= {$trainingSample}
                               THEN s.d_quantity::numeric / NULLIF(s.t1_quantity + s.d_quantity + s.t2_quantity + s.p_quantity, 0)
                           WHEN a.sample_quantity >= {$trainingSample}
                               THEN a.d_quantity::numeric / NULLIF(a.t1_quantity + a.d_quantity + a.t2_quantity + a.p_quantity, 0)
                           ELSE NULL
                       END AS pre_handover_rate,
                       CASE
                           WHEN s.sample_quantity >= {$trainingSample}
                               THEN (s.d_quantity + s.t2_quantity + s.p_quantity)::numeric / NULLIF(s.t1_quantity + s.d_quantity + s.t2_quantity + s.p_quantity, 0)
                           WHEN a.sample_quantity >= {$trainingSample}
                               THEN (a.d_quantity + a.t2_quantity + a.p_quantity)::numeric / NULLIF(a.t1_quantity + a.d_quantity + a.t2_quantity + a.p_quantity, 0)
                           ELSE NULL
                       END AS pre_handover_eligible_rate,
                       CASE
                           WHEN s.sample_quantity >= {$trainingSample}
                               THEN s.d_quantity::numeric / NULLIF(s.d_quantity + s.t2_quantity + s.p_quantity, 0)
                           WHEN a.sample_quantity >= {$trainingSample}
                               THEN a.d_quantity::numeric / NULLIF(a.d_quantity + a.t2_quantity + a.p_quantity, 0)
                           ELSE NULL
                       END AS post_handover_rate
                FROM tenant_outcome o
                LEFT JOIN sku_training s
                  ON s.marketplace_account_id = o.marketplace_account_id
                 AND s.marketplace_sku = o.marketplace_sku
                LEFT JOIN account_training a
                  ON a.marketplace_account_id = o.marketplace_account_id
                WHERE o.business_date >= :from
                  AND o.business_date <= :to
            ),
            forecast AS (
                SELECT marketplace_sku,
                       SUM(quantity)::bigint AS ordered_quantity,
                       COALESCE(SUM(quantity) FILTER (WHERE outcome IS NOT NULL), 0)::bigint AS resolved_quantity,
                       BOOL_OR(
                           outcome IS NULL AND (
                               NOT is_forecast_eligible
                               OR (handed_over_at IS NULL AND pre_handover_rate IS NULL)
                               OR (handed_over_at IS NOT NULL AND post_handover_rate IS NULL)
                           )
                       ) AS missing_rate,
                       SUM(CASE
                           WHEN outcome = 'D' THEN quantity::numeric
                           WHEN outcome IS NULL AND is_forecast_eligible AND handed_over_at IS NULL THEN quantity * pre_handover_rate
                           WHEN outcome IS NULL AND is_forecast_eligible AND handed_over_at IS NOT NULL THEN quantity * post_handover_rate
                           ELSE 0::numeric
                       END) AS projected_quantity,
                       SUM(CASE
                           WHEN outcome IN ('D', 'T2', 'P') THEN quantity::numeric
                           WHEN outcome IS NULL AND is_forecast_eligible AND handed_over_at IS NULL THEN quantity * pre_handover_eligible_rate
                           WHEN outcome IS NULL AND is_forecast_eligible AND handed_over_at IS NOT NULL THEN quantity::numeric
                           ELSE 0::numeric
                       END) AS projected_eligible_quantity
                FROM current_rows
                GROUP BY marketplace_sku
            )
            , forecast_rows AS (
                SELECT marketplace_sku,
                       ordered_quantity,
                       resolved_quantity,
                       CASE WHEN missing_rate THEN NULL ELSE ROUND(projected_quantity)::int END AS projected_buyout_quantity,
                       CASE WHEN missing_rate THEN NULL ELSE projected_quantity END AS projected_buyout_quantity_exact,
                       CASE WHEN missing_rate THEN NULL ELSE projected_eligible_quantity END AS projected_eligible_quantity_exact,
                       CASE WHEN missing_rate OR projected_eligible_quantity = 0 THEN NULL
                            ELSE ROUND(10000::numeric * projected_quantity / projected_eligible_quantity)::int END AS projected_buyout_rate_bps,
                       ROUND(10000::numeric * resolved_quantity / NULLIF(ordered_quantity, 0))::int AS resolution_rate_bps
                FROM forecast
            )
            SELECT forecast_rows.*,
                   SUM(ordered_quantity) OVER ()::bigint AS summary_ordered_quantity,
                   SUM(resolved_quantity) OVER ()::bigint AS summary_resolved_quantity,
                   CASE WHEN COUNT(*) FILTER (WHERE projected_buyout_quantity_exact IS NULL) OVER () > 0
                        THEN NULL ELSE ROUND(SUM(projected_buyout_quantity_exact) OVER ())::int END AS summary_projected_buyout_quantity,
                   CASE WHEN COUNT(*) FILTER (WHERE projected_buyout_quantity_exact IS NULL) OVER () > 0
                             OR SUM(projected_eligible_quantity_exact) OVER () = 0
                        THEN NULL
                        ELSE ROUND(10000::numeric * SUM(projected_buyout_quantity_exact) OVER () / SUM(projected_eligible_quantity_exact) OVER ())::int
                   END AS summary_projected_buyout_rate_bps,
                   ROUND(10000::numeric * SUM(resolved_quantity) OVER () / NULLIF(SUM(ordered_quantity) OVER (), 0))::int AS summary_resolution_rate_bps
            FROM forecast_rows
            SQL;

        $utc = new \DateTimeZone('UTC');
        $moscow = new \DateTimeZone('Europe/Moscow');
        $query = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('('.$source.')', 'forecast')
            ->setParameter('companyId', $companyId)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->setParameter('asOf', $asOf->setTimezone($utc)->format('Y-m-d H:i:s'))
            ->setParameter('asOfMoscow', $asOf->setTimezone($moscow)->format('Y-m-d H:i:s'))
            ->orderBy('marketplace_sku', 'ASC');

        // limit=0 используется только полным summary aggregate поверх
        // этого SQL; наружный запрос всё равно возвращает одну строку.
        if ($limit > 0) {
            $query->setMaxResults($limit + 1);
        }

        if (null !== $cursor) {
            $query->andWhere('marketplace_sku > :cursor')->setParameter('cursor', $cursor);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): BuyoutForecastRow
    {
        return new BuyoutForecastRow(
            marketplaceSku: self::string($row['marketplace_sku'] ?? null),
            orderedQuantity: self::integer($row['ordered_quantity'] ?? null),
            resolvedQuantity: self::integer($row['resolved_quantity'] ?? null),
            projectedBuyoutQuantity: self::nullableInteger($row['projected_buyout_quantity'] ?? null),
            projectedBuyoutRateBps: self::nullableInteger($row['projected_buyout_rate_bps'] ?? null),
            resolutionRateBps: self::integer($row['resolution_rate_bps'] ?? null),
        );
    }

    private static function string(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected string in buyout forecast row.');
        }

        return $value;
    }

    private static function integer(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && 1 === preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException('Expected integer in buyout forecast row.');
    }

    private static function nullableInteger(mixed $value): ?int
    {
        return null === $value ? null : self::integer($value);
    }
}
