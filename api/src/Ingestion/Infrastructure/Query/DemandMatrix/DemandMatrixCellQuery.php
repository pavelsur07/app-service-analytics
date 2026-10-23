<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\DemandMatrix;

use App\Ingestion\Infrastructure\Query\Demand\DemandSql;
use App\Ingestion\Infrastructure\Query\Demand\StockoutSql;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Ячейки матрицы для SKU одной страницы — одним запросом, не в цикле.
 *
 * Сетка — SKU страницы × кластеры покупателя со спросом компании.
 * Ячейка отдаётся, если в ней были заказы окна, или если товар здесь
 * когда-либо продавался, а в окне были дни без товара: пустая ячейка
 * там — «спрос неизвестен», а не «спроса нет» (план, решение 3). Где
 * товар не продавался никогда, пустая ячейка с дефицитом не отдаётся:
 * на проде такие пометки покрывали почти всю сетку и перестали что-либо
 * значить (решение владельца 2026-10-23).
 * Ячейка без кластера — только с заказами: остаток по неизвестному
 * кластеру не бывает.
 */
final readonly class DemandMatrixCellQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param list<string> $skus SKU страницы; не пуст
     */
    public function build(string $companyId, \DateTimeImmutable $today, array $skus): QueryBuilder
    {
        $rate = DemandMatrixSql::rateSelect('g');
        $soldBefore = DemandSql::everSoldClustersCte();
        $source = 'WITH '.DemandMatrixSql::aggregatesCte().', '.StockoutSql::cte().<<<SQL
            ,
            page AS (
                SELECT marketplace_sku, orders28 - unknown_cluster_orders28 AS known_orders28
                FROM by_sku
                WHERE marketplace_sku IN (:skus)
            ),
            clusters AS (
                SELECT DISTINCT cluster FROM demand_lines WHERE cluster IS NOT NULL
            ),
            {$soldBefore},
            grid AS (
                SELECT p.marketplace_sku, c.cluster, p.known_orders28,
                       COALESCE(b.orders7, 0) AS orders7,
                       COALESCE(b.orders14, 0) AS orders14,
                       COALESCE(b.orders28, 0) AS orders28,
                       GREATEST(0, COALESCE(rc.days, 0) - COALESCE(pc.days, 0))::int AS stockout_days,
                       (es.marketplace_sku IS NOT NULL) AS ever_sold
                FROM page p
                CROSS JOIN clusters c
                LEFT JOIN by_cell b ON b.marketplace_sku = p.marketplace_sku AND b.cluster = c.cluster
                LEFT JOIN requested_count rc ON rc.marketplace_sku = p.marketplace_sku
                LEFT JOIN positive_count pc ON pc.marketplace_sku = p.marketplace_sku AND pc.cluster = c.cluster
                LEFT JOIN ever_sold es ON es.marketplace_sku = p.marketplace_sku AND es.cluster = c.cluster
                UNION ALL
                SELECT p.marketplace_sku, NULL, p.known_orders28, b.orders7, b.orders14, b.orders28, 0, true
                FROM page p
                JOIN by_cell b ON b.marketplace_sku = p.marketplace_sku AND b.cluster IS NULL
            )
            SELECT g.marketplace_sku, g.cluster, g.orders7, g.orders14, g.orders28, g.stockout_days,
                   CASE WHEN g.cluster IS NOT NULL AND g.known_orders28 > 0
                        THEN ROUND(10000::numeric * g.orders28 / g.known_orders28)::int
                   END AS share_bps,
                   {$rate}
            FROM grid g
            WHERE g.orders28 > 0 OR (g.stockout_days > 0 AND g.ever_sold)
            SQL;

        return $this->connection->createQueryBuilder()
            ->select('cell.*')
            ->from('('.$source.')', 'cell')
            ->setParameters(DemandMatrixSql::parameters($companyId, $today))
            ->setParameter('skus', $skus, ArrayParameterType::STRING)
            ->orderBy('cell.marketplace_sku', 'ASC')
            ->addOrderBy('cell.orders28', 'DESC')
            ->addOrderBy('cell.cluster', 'ASC');
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): DemandMatrixCellRow
    {
        return new DemandMatrixCellRow(
            marketplaceSku: DemandMatrixSql::string($row['marketplace_sku']),
            cluster: DemandMatrixSql::nullableString($row['cluster']),
            orders7: DemandMatrixSql::int($row['orders7']),
            orders14: DemandMatrixSql::int($row['orders14']),
            orders28: DemandMatrixSql::int($row['orders28']),
            demandMilliPerDay: DemandMatrixSql::int($row['demand_milli_per_day']),
            shareBps: DemandMatrixSql::nullableInt($row['share_bps']),
            trendBps: DemandMatrixSql::nullableInt($row['trend_bps']),
            stockoutDays: DemandMatrixSql::int($row['stockout_days']),
        );
    }
}
