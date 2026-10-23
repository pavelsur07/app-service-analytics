<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\DemandMatrix;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Строки матрицы спроса — SKU с заказами за 28 дней и их итогами.
 * Сверху — больше заказов. Keyset, лимит 50/200 (CLAUDE.md §5).
 */
final readonly class DemandMatrixSkuQuery
{
    public const int DEFAULT_LIMIT = 50;
    public const int MAX_LIMIT = 200;

    public function __construct(private Connection $connection)
    {
    }

    public function build(
        string $companyId,
        \DateTimeImmutable $today,
        string $search,
        int $limit,
        ?DemandMatrixCursor $cursor = null,
    ): QueryBuilder {
        $source = 'WITH '.DemandMatrixSql::aggregatesCte()
            .' SELECT s.*, '.DemandMatrixSql::rateSelect('s').' FROM by_sku s';

        $query = $this->connection->createQueryBuilder()
            ->select('sku.*', 'listing.offer_id', 'listing.name')
            ->from('('.$source.')', 'sku')
            ->leftJoin(
                'sku',
                '(SELECT DISTINCT ON (ml.company_id, ml.marketplace_sku) ml.company_id, ml.marketplace_sku, ml.offer_id, ml.name FROM marketplace_listing ml WHERE ml.company_id = :companyId ORDER BY ml.company_id, ml.marketplace_sku, ml.first_seen_at DESC, ml.marketplace_account_id DESC)',
                'listing',
                'listing.company_id = :companyId AND listing.marketplace_sku = sku.marketplace_sku',
            )
            ->setParameters(DemandMatrixSql::parameters($companyId, $today))
            ->orderBy('sku.orders28', 'DESC')
            ->addOrderBy('sku.marketplace_sku', 'ASC')
            // +1 — узнать, есть ли следующая страница, без COUNT(*) (§5).
            ->setMaxResults($limit + 1);

        if ('' !== $search) {
            // Ввод — буквальный текст: `%` и `_` ищутся как символы.
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
            $query->andWhere("(sku.marketplace_sku ILIKE :pattern ESCAPE '!' OR listing.offer_id ILIKE :pattern ESCAPE '!' OR listing.name ILIKE :pattern ESCAPE '!')")
                ->setParameter('pattern', '%'.$escaped.'%');
        }
        if (null !== $cursor) {
            $query->andWhere('(sku.orders28 < :cursorOrders OR (sku.orders28 = :cursorOrders AND sku.marketplace_sku > :cursorSku))')
                ->setParameter('cursorOrders', $cursor->orders28)
                ->setParameter('cursorSku', $cursor->marketplaceSku);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): DemandMatrixSkuRow
    {
        return new DemandMatrixSkuRow(
            marketplaceSku: DemandMatrixSql::string($row['marketplace_sku']),
            offerId: DemandMatrixSql::nullableString($row['offer_id']),
            name: DemandMatrixSql::nullableString($row['name']),
            orders7: DemandMatrixSql::int($row['orders7']),
            orders14: DemandMatrixSql::int($row['orders14']),
            orders28: DemandMatrixSql::int($row['orders28']),
            unknownClusterOrders28: DemandMatrixSql::int($row['unknown_cluster_orders28']),
            demandMilliPerDay: DemandMatrixSql::int($row['demand_milli_per_day']),
            trendBps: DemandMatrixSql::nullableInt($row['trend_bps']),
        );
    }
}
