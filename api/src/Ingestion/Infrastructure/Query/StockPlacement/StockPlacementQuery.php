<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\StockPlacement;

use App\Ingestion\Infrastructure\Query\DeliverySpeed\DeliverySpeedSql;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * «Что куда довезти»: SKU × кластер с остатком, спросом, покрытием,
 * статусом и рекомендацией. Сверху — где покупатели теряют больше всего
 * часов ожидания (вариант B), затем — больше рекомендация. Keyset, лимит
 * 50/200 (CLAUDE.md §5).
 */
final readonly class StockPlacementQuery
{
    public const int DEFAULT_LIMIT = 50;
    public const int MAX_LIMIT = 200;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<string> $snapshotAccountIds активные подключения Ozon компании
     */
    public function build(
        string $companyId,
        \DateTimeImmutable $today,
        int $targetDays,
        int $leadDays,
        ?string $status,
        int $limit,
        ?StockPlacementCursor $cursor = null,
        array $snapshotAccountIds = [],
    ): QueryBuilder {
        $source = 'WITH '.DeliverySpeedSql::timedCte().', '.DeliverySpeedSql::skuLostCte().', '
            .StockPlacementSql::placementCte()
            .', rows_ AS (SELECT '.StockPlacementSql::rowSelect().', sl.lost_hours'
            .' FROM measured m LEFT JOIN sku_lost sl ON sl.marketplace_sku = m.marketplace_sku AND sl.cluster_to = m.cluster)'
            .' SELECT r.*, COALESCE(r.lost_hours, -1) AS priority, COALESCE(r.recommended, -1) AS recommended_key FROM rows_ r';

        $query = $this->connection->createQueryBuilder()
            ->select('placement.*', 'listing.offer_id', 'listing.name')
            ->from('('.$source.')', 'placement')
            ->leftJoin(
                'placement',
                '(SELECT DISTINCT ON (ml.company_id, ml.marketplace_sku) ml.company_id, ml.marketplace_sku, ml.offer_id, ml.name FROM marketplace_listing ml WHERE ml.company_id = :companyId ORDER BY ml.company_id, ml.marketplace_sku, ml.first_seen_at DESC, ml.marketplace_account_id DESC)',
                'listing',
                'listing.company_id = :companyId AND listing.marketplace_sku = placement.marketplace_sku',
            )
            ->setParameters([
                ...StockPlacementSql::parameters($companyId, $today, $targetDays, $leadDays),
                ...StockPlacementSql::deliveryParameters($companyId, $today),
            ])
            ->setParameter('snapshotAccounts', $snapshotAccountIds, ArrayParameterType::STRING)
            ->orderBy('placement.priority', 'DESC')
            ->addOrderBy('placement.recommended_key', 'DESC')
            ->addOrderBy('placement.marketplace_sku', 'ASC')
            ->addOrderBy('placement.cluster', 'ASC')
            // +1 — узнать, есть ли следующая страница, без COUNT(*) (§5).
            ->setMaxResults($limit + 1);

        if (null !== $status) {
            $query->andWhere('placement.status = :status')->setParameter('status', $status);
        }
        if (null !== $cursor) {
            $query->andWhere('(placement.priority, placement.recommended_key) < (:cursorPriority, :cursorRecommended)'
                .' OR ((placement.priority, placement.recommended_key) = (:cursorPriority, :cursorRecommended)'
                .' AND (placement.marketplace_sku, placement.cluster) > (:cursorSku, :cursorCluster))')
                ->setParameter('cursorPriority', $cursor->priority)
                ->setParameter('cursorRecommended', $cursor->recommended)
                ->setParameter('cursorSku', $cursor->marketplaceSku)
                ->setParameter('cursorCluster', $cursor->cluster);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): StockPlacementRow
    {
        return new StockPlacementRow(
            marketplaceSku: self::string($row['marketplace_sku']),
            offerId: self::nullableString($row['offer_id']),
            name: self::nullableString($row['name']),
            cluster: self::string($row['cluster']),
            available: self::nullableInt($row['available']),
            transit: self::nullableInt($row['transit']),
            requested: self::nullableInt($row['requested']),
            sold: self::int($row['sold']),
            demandMilliPerDay: self::int($row['demand_milli_per_day']),
            coverDays: self::nullableInt($row['cover_days']),
            recommended: self::nullableInt($row['recommended']),
            status: self::string($row['status']),
            abcClass: self::string($row['abc_class']),
            zeroDays: self::int($row['zero_days']),
            lostHours: self::nullableInt($row['lost_hours']),
            adsCluster: self::nullableString($row['ads_cluster']),
            idcCluster: self::nullableInt($row['idc_cluster']),
            priority: self::int($row['priority']),
        );
    }

    public static function int(mixed $value): int
    {
        if (!\is_int($value) && !(\is_string($value) && is_numeric($value))) {
            throw new \UnexpectedValueException('Expected an integer in a stock placement row.');
        }

        return (int) $value;
    }

    public static function nullableInt(mixed $value): ?int
    {
        return null === $value ? null : self::int($value);
    }

    public static function string(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected a string in a stock placement row.');
        }

        return $value;
    }

    public static function nullableString(mixed $value): ?string
    {
        return null === $value ? null : self::string($value);
    }
}
