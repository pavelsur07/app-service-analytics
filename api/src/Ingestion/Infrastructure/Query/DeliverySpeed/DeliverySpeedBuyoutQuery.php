<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\DeliverySpeed;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Выкуп по скорости доставки: фактический выкуп D / (D + T2 + P)
 * (ADR-019/020, как в «Выкупе») среди прибывших вживую отправлений,
 * разложенных по корзинам дней до прибытия.
 */
final readonly class DeliverySpeedBuyoutQuery
{
    /** Нижние границы корзин, дней: 0–2, 3–4, 5–7, 8+. */
    public const array BUCKET_MIN_DAYS = [0, 3, 5, 8];

    public function __construct(private Connection $connection)
    {
    }

    public function build(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        $cases = [];
        $bounds = self::BUCKET_MIN_DAYS;
        for ($i = \count($bounds) - 1; $i >= 1; --$i) {
            $cases[] = "WHEN t.delivery_seconds >= {$bounds[$i]} * 86400 THEN {$bounds[$i]}";
        }
        $bucket = 'CASE '.implode(' ', $cases).' ELSE 0 END';

        $source = 'WITH '.DeliverySpeedSql::timedCte().<<<SQL
            ,
            tenant_outcome AS MATERIALIZED (
                SELECT marketplace_account_id, posting_number, quantity, outcome
                FROM buyout_outcome
                WHERE company_id = :companyId
            ),
            bucketed AS (
                SELECT {$bucket} AS min_days, t.marketplace_account_id, t.posting_number
                FROM timed t
                WHERE t.live AND t.arrived
            )
            SELECT b.min_days,
                   COUNT(DISTINCT (b.marketplace_account_id, b.posting_number))::bigint AS postings,
                   COALESCE(SUM(o.quantity) FILTER (WHERE o.outcome = 'D'), 0)::bigint AS delivered_quantity,
                   COALESCE(SUM(o.quantity) FILTER (WHERE o.outcome IN ('D', 'T2', 'P')), 0)::bigint AS resolved_quantity,
                   ROUND(10000::numeric * COALESCE(SUM(o.quantity) FILTER (WHERE o.outcome = 'D'), 0)
                         / NULLIF(SUM(o.quantity) FILTER (WHERE o.outcome IN ('D', 'T2', 'P')), 0))::int AS buyout_rate_bps
            FROM bucketed b
            LEFT JOIN tenant_outcome o
                   ON o.marketplace_account_id = b.marketplace_account_id
                  AND o.posting_number = b.posting_number
            GROUP BY b.min_days
            SQL;

        return $this->connection->createQueryBuilder()
            ->select('bucket.*')
            ->from('('.$source.')', 'bucket')
            ->setParameters(DeliverySpeedSql::parameters($companyId, $from, $to))
            ->orderBy('bucket.min_days', 'ASC');
    }

    /**
     * Все корзины по порядку, включая пустые; ниже порога отправлений выкуп
     * не отдаётся.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<DeliverySpeedBucketRow>
     */
    public static function mapRows(array $rows): array
    {
        $byMin = [];
        foreach ($rows as $row) {
            $byMin[DeliverySpeedMetrics::int($row['min_days'])] = $row;
        }

        $buckets = [];
        $bounds = self::BUCKET_MIN_DAYS;
        foreach ($bounds as $i => $min) {
            $row = $byMin[$min] ?? null;
            $postings = null === $row ? 0 : DeliverySpeedMetrics::int($row['postings']);
            $delivered = null === $row ? 0 : DeliverySpeedMetrics::int($row['delivered_quantity']);
            $resolved = null === $row ? 0 : DeliverySpeedMetrics::int($row['resolved_quantity']);
            $buckets[] = new DeliverySpeedBucketRow(
                minDays: $min,
                maxDays: $bounds[$i + 1] ?? null,
                postings: $postings,
                deliveredQuantity: $delivered,
                resolvedQuantity: $resolved,
                buyoutRateBps: null !== $row && $postings >= DeliverySpeedSql::MIN_POSTINGS
                    ? DeliverySpeedMetrics::nullableInt($row['buyout_rate_bps'])
                    : null,
            );
        }

        return $buckets;
    }
}
