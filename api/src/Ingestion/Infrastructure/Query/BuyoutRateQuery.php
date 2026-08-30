<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Quantity-weighted actual rates. Все суммы и деления выполняет PostgreSQL;
 * R исключён из buyout/conversion denominators согласно ADR-019.
 */
final readonly class BuyoutRateQuery
{
    public const int DEFAULT_LIMIT = 50;
    public const int MAX_LIMIT = 200;

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
            aggregated AS (
                SELECT o.marketplace_sku,
                       SUM(o.quantity)::bigint AS ordered_quantity,
                       COALESCE(SUM(o.quantity) FILTER (WHERE o.outcome = 'T1'), 0)::bigint AS t1_quantity,
                       COALESCE(SUM(o.quantity) FILTER (WHERE o.outcome = 'D'), 0)::bigint AS delivered_quantity,
                       COALESCE(SUM(o.quantity) FILTER (WHERE o.outcome = 'T2'), 0)::bigint AS t2_quantity,
                       COALESCE(SUM(o.quantity) FILTER (WHERE o.outcome = 'P'), 0)::bigint AS partial_return_quantity,
                       COALESCE(SUM(o.quantity) FILTER (WHERE o.outcome = 'R'), 0)::bigint AS client_return_quantity,
                       COALESCE(SUM(o.quantity) FILTER (WHERE o.outcome IS NULL), 0)::bigint AS unresolved_quantity,
                       CASE WHEN BOOL_AND(m.p95_seconds IS NOT NULL AND :cohortAgeSeconds > m.p95_seconds)
                            THEN 'mature' ELSE 'preliminary' END AS maturity_status
                FROM buyout_outcome o
                LEFT JOIN maturity m ON m.marketplace_account_id = o.marketplace_account_id
                WHERE o.company_id = :companyId
                  AND o.business_date >= :from
                  AND o.business_date <= :to
                GROUP BY o.marketplace_sku
            )
            SELECT marketplace_sku,
                   ordered_quantity,
                   t1_quantity,
                   delivered_quantity,
                   t2_quantity,
                   partial_return_quantity,
                   client_return_quantity,
                   unresolved_quantity,
                   CASE WHEN (t1_quantity + delivered_quantity + t2_quantity + partial_return_quantity) = 0 THEN NULL
                        ELSE ROUND(10000::numeric * delivered_quantity / (t1_quantity + delivered_quantity + t2_quantity + partial_return_quantity))::int END AS conversion_rate_bps,
                   CASE WHEN (delivered_quantity + t2_quantity + partial_return_quantity) = 0 THEN NULL
                        ELSE ROUND(10000::numeric * delivered_quantity / (delivered_quantity + t2_quantity + partial_return_quantity))::int END AS actual_buyout_rate_bps,
                   ROUND(10000::numeric * (t1_quantity + delivered_quantity + t2_quantity + partial_return_quantity + client_return_quantity) / NULLIF(ordered_quantity, 0))::int AS resolution_rate_bps,
                   ROUND(10000::numeric * t1_quantity / NULLIF(ordered_quantity, 0))::int AS t1_rate_bps,
                   ROUND(10000::numeric * t2_quantity / NULLIF(ordered_quantity, 0))::int AS t2_rate_bps,
                   ROUND(10000::numeric * partial_return_quantity / NULLIF(ordered_quantity, 0))::int AS partial_return_rate_bps,
                   maturity_status
            FROM aggregated
            SQL;

        $moscow = new \DateTimeZone('Europe/Moscow');
        $utc = new \DateTimeZone('UTC');
        $boundary = \DateTimeImmutable::createFromFormat('!Y-m-d', $to->format('Y-m-d'), $moscow);
        if (false === $boundary) {
            throw new \InvalidArgumentException('Invalid buyout report end date.');
        }
        $cohortBoundary = $boundary->modify('+1 day')->setTimezone($utc);
        $cohortAgeSeconds = $asOf->setTimezone($utc)->getTimestamp() - $cohortBoundary->getTimestamp();

        $query = $this->connection->createQueryBuilder()
            ->select('rate.*', 'listing.offer_id', 'listing.name')
            ->from('('.$source.')', 'rate')
            ->leftJoin(
                'rate',
                '(SELECT DISTINCT ON (ml.company_id, ml.marketplace_sku) ml.company_id, ml.marketplace_sku, ml.offer_id, ml.name FROM marketplace_listing ml WHERE ml.company_id = :companyId ORDER BY ml.company_id, ml.marketplace_sku, ml.first_seen_at DESC, ml.marketplace_account_id DESC)',
                'listing',
                'listing.company_id = :companyId AND listing.marketplace_sku = rate.marketplace_sku',
            )
            ->setParameter('companyId', $companyId)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->setParameter('asOf', $asOf->setTimezone($utc)->format('Y-m-d H:i:s'))
            ->setParameter('cohortAgeSeconds', $cohortAgeSeconds)
            ->orderBy('rate.marketplace_sku', 'ASC')
            ->setMaxResults($limit + 1);

        if (null !== $cursor) {
            $query->andWhere('rate.marketplace_sku > :cursor')->setParameter('cursor', $cursor);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): BuyoutRateRow
    {
        return new BuyoutRateRow(
            marketplaceSku: self::string($row['marketplace_sku'] ?? null),
            offerId: self::nullableString($row['offer_id'] ?? null),
            name: self::nullableString($row['name'] ?? null),
            orderedQuantity: self::integer($row['ordered_quantity'] ?? null),
            t1Quantity: self::integer($row['t1_quantity'] ?? null),
            deliveredQuantity: self::integer($row['delivered_quantity'] ?? null),
            t2Quantity: self::integer($row['t2_quantity'] ?? null),
            partialReturnQuantity: self::integer($row['partial_return_quantity'] ?? null),
            clientReturnQuantity: self::integer($row['client_return_quantity'] ?? null),
            unresolvedQuantity: self::integer($row['unresolved_quantity'] ?? null),
            conversionRateBps: self::nullableInteger($row['conversion_rate_bps'] ?? null),
            actualBuyoutRateBps: self::nullableInteger($row['actual_buyout_rate_bps'] ?? null),
            resolutionRateBps: self::nullableInteger($row['resolution_rate_bps'] ?? null),
            t1RateBps: self::nullableInteger($row['t1_rate_bps'] ?? null),
            t2RateBps: self::nullableInteger($row['t2_rate_bps'] ?? null),
            partialReturnRateBps: self::nullableInteger($row['partial_return_rate_bps'] ?? null),
            maturityStatus: self::string($row['maturity_status'] ?? null),
        );
    }

    private static function string(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected string in buyout rate row.');
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

        throw new \UnexpectedValueException('Expected integer in buyout rate row.');
    }

    private static function nullableInteger(mixed $value): ?int
    {
        return null === $value ? null : self::integer($value);
    }

    private static function nullableString(mixed $value): ?string
    {
        return null === $value ? null : self::string($value);
    }
}
