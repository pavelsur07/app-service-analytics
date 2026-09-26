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
        $min = DeliverySpeedSql::MIN_POSTINGS;
        $source = 'WITH '.DeliverySpeedSql::timedCte().<<<SQL
            ,
            by_cluster AS (
                SELECT cluster_to,
                       COUNT(*) FILTER (WHERE arrived AND is_local) AS local_arrived,
                       COUNT(*) FILTER (WHERE arrived AND NOT is_local) AS nonlocal_arrived,
                       percentile_disc(0.5) WITHIN GROUP (ORDER BY delivery_seconds) FILTER (WHERE arrived AND is_local) AS median_local_seconds,
                       percentile_disc(0.5) WITHIN GROUP (ORDER BY delivery_seconds) FILTER (WHERE arrived AND NOT is_local) AS median_nonlocal_seconds
                FROM timed
                WHERE live AND has_clusters
                GROUP BY cluster_to
            ),
            cluster_gap AS (
                SELECT cluster_to, median_local_seconds, median_nonlocal_seconds,
                       GREATEST(0, median_nonlocal_seconds - median_local_seconds) AS gap_seconds
                FROM by_cluster
                WHERE local_arrived >= {$min} AND nonlocal_arrived >= {$min}
            ),
            lines AS (
                SELECT marketplace_account_id, posting_number, marketplace_sku, quantity
                FROM sales_fact
                WHERE company_id = :companyId
                  AND business_date BETWEEN :from AND :to
                  AND posting_number IS NOT NULL
            ),
            by_sku AS (
                SELECT l.marketplace_sku, t.cluster_to,
                       SUM(l.quantity)::bigint AS quantity,
                       COALESCE(SUM(l.quantity) FILTER (WHERE NOT t.is_local), 0)::bigint AS nonlocal_quantity,
                       COUNT(DISTINCT (t.marketplace_account_id, t.posting_number)) FILTER (WHERE t.arrived AND NOT t.is_local) AS nonlocal_arrived_postings
                FROM lines l
                JOIN timed t
                  ON t.marketplace_account_id = l.marketplace_account_id
                 AND t.posting_number = l.posting_number
                WHERE t.live AND t.has_clusters
                GROUP BY l.marketplace_sku, t.cluster_to
            )
            SELECT b.*, g.median_local_seconds, g.median_nonlocal_seconds,
                   CEIL(b.nonlocal_arrived_postings::numeric * g.gap_seconds / 3600)::bigint AS lost_hours
            FROM by_sku b
            JOIN cluster_gap g ON g.cluster_to = b.cluster_to
            WHERE g.gap_seconds > 0 AND b.nonlocal_arrived_postings > 0
            SQL;

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
