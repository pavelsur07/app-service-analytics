<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\DeliverySpeed;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * «Что довезти первым»: SKU × кластер доставки по потерянным часам
 * ожидания. Разница медиан берётся по кластеру, а не по SKU: по одному
 * товару прибывших отправлений мало, и медиана шумела бы. В списке только
 * пары, где потеря есть (> 0). Keyset, не offset (CLAUDE.md §5).
 */
final readonly class DeliverySpeedSkuQuery
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
        int $limit,
        ?DeliverySpeedSkuCursor $cursor = null,
    ): QueryBuilder {
        $source = 'WITH '.DeliverySpeedSql::timedCte().', '.DeliverySpeedSql::skuLostCte().' SELECT * FROM sku_lost';

        $query = $this->connection->createQueryBuilder()
            ->select('sku.*', 'listing.offer_id', 'listing.name')
            ->from('('.$source.')', 'sku')
            ->leftJoin(
                'sku',
                '(SELECT DISTINCT ON (ml.company_id, ml.marketplace_sku) ml.company_id, ml.marketplace_sku, ml.offer_id, ml.name FROM marketplace_listing ml WHERE ml.company_id = :companyId ORDER BY ml.company_id, ml.marketplace_sku, ml.first_seen_at DESC, ml.marketplace_account_id DESC)',
                'listing',
                'listing.company_id = :companyId AND listing.marketplace_sku = sku.marketplace_sku',
            )
            ->setParameters(DeliverySpeedSql::parameters($companyId, $from, $to))
            ->orderBy('sku.lost_hours', 'DESC')
            ->addOrderBy('sku.marketplace_sku', 'ASC')
            ->addOrderBy('sku.cluster_to', 'ASC')
            // +1 — узнать, есть ли следующая страница, без COUNT(*) (CLAUDE.md §5).
            ->setMaxResults($limit + 1);

        if (null !== $cursor) {
            $query->andWhere('(sku.lost_hours < :cursorLost OR (sku.lost_hours = :cursorLost AND (sku.marketplace_sku, sku.cluster_to) > (:cursorSku, :cursorCluster)))')
                ->setParameter('cursorLost', $cursor->lostHours)
                ->setParameter('cursorSku', $cursor->marketplaceSku)
                ->setParameter('cursorCluster', $cursor->clusterTo);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): DeliverySpeedSkuRow
    {
        return new DeliverySpeedSkuRow(
            marketplaceSku: DeliverySpeedMetrics::string($row['marketplace_sku']),
            offerId: DeliverySpeedMetrics::nullableString($row['offer_id']),
            name: DeliverySpeedMetrics::nullableString($row['name']),
            clusterTo: DeliverySpeedMetrics::string($row['cluster_to']),
            quantity: DeliverySpeedMetrics::int($row['quantity']),
            nonlocalQuantity: DeliverySpeedMetrics::int($row['nonlocal_quantity']),
            nonlocalArrivedPostings: DeliverySpeedMetrics::int($row['nonlocal_arrived_postings']),
            clusterMedianLocalSeconds: DeliverySpeedMetrics::int($row['median_local_seconds']),
            clusterMedianNonlocalSeconds: DeliverySpeedMetrics::int($row['median_nonlocal_seconds']),
            lostHours: DeliverySpeedMetrics::int($row['lost_hours']),
        );
    }
}
