<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/** Дневной actual/projected ряд одной SKU, одним bounded SQL-запросом. */
final readonly class BuyoutDailyQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function build(
        string $companyId,
        string $marketplaceSku,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $asOf,
    ): QueryBuilder {
        $maturitySample = BuyoutMaturityQuery::MIN_SAMPLE_SIZE;
        $trainingSample = BuyoutForecastQuery::MIN_TRAINING_QUANTITY;
        $source = <<<SQL
            WITH posting_intervals AS (
                SELECT DISTINCT company_id, marketplace_account_id, posting_number,
                       EXTRACT(EPOCH FROM (resolved_at - handed_over_at))::bigint AS duration_seconds
                FROM buyout_outcome
                WHERE company_id = :companyId
                  AND outcome IS NOT NULL
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
                FROM buyout_outcome o
                JOIN maturity m ON m.marketplace_account_id = o.marketplace_account_id
                WHERE o.company_id = :companyId
                  AND m.p95_seconds IS NOT NULL
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
                       m.p95_seconds AS current_p95_seconds,
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
                FROM buyout_outcome o
                LEFT JOIN maturity m ON m.marketplace_account_id = o.marketplace_account_id
                LEFT JOIN sku_training s
                  ON s.marketplace_account_id = o.marketplace_account_id
                 AND s.marketplace_sku = o.marketplace_sku
                LEFT JOIN account_training a
                  ON a.marketplace_account_id = o.marketplace_account_id
                WHERE o.company_id = :companyId
                  AND o.marketplace_sku = :marketplaceSku
                  AND o.business_date >= :from
                  AND o.business_date <= :to
            ),
            daily AS (
                SELECT business_date,
                       SUM(quantity)::bigint AS ordered_quantity,
                       COALESCE(SUM(quantity) FILTER (WHERE outcome IS NOT NULL), 0)::bigint AS resolved_quantity,
                       COALESCE(SUM(quantity) FILTER (WHERE outcome = 'D'), 0)::bigint AS d_quantity,
                       COALESCE(SUM(quantity) FILTER (WHERE outcome = 'T2'), 0)::bigint AS t2_quantity,
                       COALESCE(SUM(quantity) FILTER (WHERE outcome = 'P'), 0)::bigint AS p_quantity,
                       BOOL_AND(
                           current_p95_seconds IS NOT NULL
                           AND EXTRACT(EPOCH FROM (
                               :asOf::timestamp - ((business_date + 1)::timestamp AT TIME ZONE 'Europe/Moscow' AT TIME ZONE 'UTC')
                           )) > current_p95_seconds
                       ) AS mature,
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
                GROUP BY business_date
            )
            SELECT business_date,
                   CASE WHEN mature AND (d_quantity + t2_quantity + p_quantity) > 0
                        THEN ROUND(10000::numeric * d_quantity / (d_quantity + t2_quantity + p_quantity))::int
                        ELSE NULL END AS actual_buyout_rate_bps,
                   CASE WHEN missing_rate OR projected_eligible_quantity = 0 THEN NULL
                        ELSE ROUND(10000::numeric * projected_quantity / projected_eligible_quantity)::int END AS projected_buyout_rate_bps,
                   ROUND(10000::numeric * resolved_quantity / NULLIF(ordered_quantity, 0))::int AS resolution_rate_bps,
                   ordered_quantity,
                   resolved_quantity,
                   CASE WHEN missing_rate THEN NULL ELSE ROUND(projected_quantity)::int END AS projected_buyout_quantity
            FROM daily
            SQL;

        $utc = new \DateTimeZone('UTC');
        $moscow = new \DateTimeZone('Europe/Moscow');

        return $this->connection->createQueryBuilder()
            ->select('*')
            ->from('('.$source.')', 'daily')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplaceSku', $marketplaceSku)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->setParameter('asOf', $asOf->setTimezone($utc)->format('Y-m-d H:i:s'))
            ->setParameter('asOfMoscow', $asOf->setTimezone($moscow)->format('Y-m-d H:i:s'))
            ->orderBy('business_date', 'ASC')
            ->setMaxResults(91);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): BuyoutDailyRow
    {
        return new BuyoutDailyRow(
            date: self::string($row['business_date'] ?? null),
            actualBuyoutRateBps: self::nullableInteger($row['actual_buyout_rate_bps'] ?? null),
            projectedBuyoutRateBps: self::nullableInteger($row['projected_buyout_rate_bps'] ?? null),
            resolutionRateBps: self::integer($row['resolution_rate_bps'] ?? null),
            orderedQuantity: self::integer($row['ordered_quantity'] ?? null),
            resolvedQuantity: self::integer($row['resolved_quantity'] ?? null),
            projectedBuyoutQuantity: self::nullableInteger($row['projected_buyout_quantity'] ?? null),
        );
    }

    private static function string(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected string in daily buyout row.');
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

        throw new \UnexpectedValueException('Expected integer in daily buyout row.');
    }

    private static function nullableInteger(mixed $value): ?int
    {
        return null === $value ? null : self::integer($value);
    }
}
