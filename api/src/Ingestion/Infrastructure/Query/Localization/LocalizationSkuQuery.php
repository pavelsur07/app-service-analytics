<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Localization;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * «SKU × кластер доставки»: сколько штук купили в кластере, какая доля
 * пришла из него же и откуда везли остальное. Сверху — больше всего
 * нелокальных штук: это список «что куда довезти». Keyset, не offset:
 * строк столько, сколько пар SKU × кластер у клиента (CLAUDE.md §5).
 */
final readonly class LocalizationSkuQuery
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
        ?LocalizationSkuCursor $cursor = null,
    ): QueryBuilder {
        $metrics = LocalizationSql::metricsSelect();
        $source = 'WITH '.LocalizationSql::linesCte().<<<SQL
            ,
            main_source AS (
                SELECT DISTINCT ON (marketplace_sku, cluster_to) marketplace_sku, cluster_to, cluster_from
                FROM (
                    SELECT marketplace_sku, cluster_to, cluster_from, SUM(quantity) AS quantity
                    FROM lines
                    WHERE has_clusters
                    GROUP BY marketplace_sku, cluster_to, cluster_from
                ) per_source
                ORDER BY marketplace_sku, cluster_to, quantity DESC, cluster_from
            ),
            by_sku AS (
                SELECT marketplace_sku, cluster_to, {$metrics}
                FROM lines
                WHERE in_cluster
                GROUP BY marketplace_sku, cluster_to
            )
            SELECT b.*, m.cluster_from AS main_source_cluster
            FROM by_sku b
            JOIN main_source m ON m.marketplace_sku = b.marketplace_sku AND m.cluster_to = b.cluster_to
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
            ->setParameter('companyId', $companyId)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('sku.nonlocal_quantity', 'DESC')
            ->addOrderBy('sku.marketplace_sku', 'ASC')
            ->addOrderBy('sku.cluster_to', 'ASC')
            // +1 — узнать, есть ли следующая страница, без COUNT(*) (CLAUDE.md §5).
            ->setMaxResults($limit + 1);

        if (null !== $cursor) {
            $query->andWhere('(sku.nonlocal_quantity < :cursorNonlocal OR (sku.nonlocal_quantity = :cursorNonlocal AND (sku.marketplace_sku, sku.cluster_to) > (:cursorSku, :cursorCluster)))')
                ->setParameter('cursorNonlocal', $cursor->nonlocalQuantity)
                ->setParameter('cursorSku', $cursor->marketplaceSku)
                ->setParameter('cursorCluster', $cursor->clusterTo);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): LocalizationSkuRow
    {
        return new LocalizationSkuRow(
            marketplaceSku: LocalizationMetrics::string($row['marketplace_sku']),
            offerId: LocalizationMetrics::nullableString($row['offer_id']),
            name: LocalizationMetrics::nullableString($row['name']),
            clusterTo: LocalizationMetrics::string($row['cluster_to']),
            mainSourceCluster: LocalizationMetrics::string($row['main_source_cluster']),
            metrics: LocalizationMetrics::fromRow($row),
        );
    }
}
