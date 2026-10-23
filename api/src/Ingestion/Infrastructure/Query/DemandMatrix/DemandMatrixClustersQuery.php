<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\DemandMatrix;

use App\Ingestion\Infrastructure\Query\Demand\DemandSql;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Колонки матрицы — кластеры покупателя со спросом компании за 28 дней,
 * больше заказов — левее; «кластер не указан» (null) — последней.
 * Одинаковы на всех страницах: колонки не скачут при подгрузке. Кластеров
 * у Ozon десятки, MAX_CLUSTERS — ограничение списка, а не пагинация (§5).
 */
final readonly class DemandMatrixClustersQuery
{
    public const int MAX_CLUSTERS = 200;

    public function __construct(private Connection $connection)
    {
    }

    public function build(string $companyId, \DateTimeImmutable $today): QueryBuilder
    {
        $source = 'WITH '.DemandSql::linesCte()
            .' SELECT cluster, SUM(quantity)::bigint AS orders28 FROM demand_lines GROUP BY cluster';

        return $this->connection->createQueryBuilder()
            ->select('c.cluster', 'c.orders28')
            ->from('('.$source.')', 'c')
            ->setParameters(DemandMatrixSql::parameters($companyId, $today))
            ->orderBy('c.cluster IS NULL', 'ASC')
            ->addOrderBy('c.orders28', 'DESC')
            ->addOrderBy('c.cluster', 'ASC')
            ->setMaxResults(self::MAX_CLUSTERS);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): DemandMatrixClusterRow
    {
        return new DemandMatrixClusterRow(
            cluster: DemandMatrixSql::nullableString($row['cluster']),
            orders28: DemandMatrixSql::int($row['orders28']),
        );
    }
}
