<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\DeliverySpeed;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Кластеры доставки: медианы локальных и нелокальных и потерянные часы
 * ожидания. Сверху — где раскладка стоит покупателям больше всего.
 */
final readonly class DeliverySpeedClusterQuery
{
    public const int LIMIT = 50;

    public function __construct(private Connection $connection)
    {
    }

    public function build(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        $source = 'WITH '.DeliverySpeedSql::timedCte()
            .', by_cluster AS (SELECT cluster_to, '.DeliverySpeedSql::metricsSelect()
            .' FROM timed WHERE live AND has_clusters GROUP BY cluster_to)'
            .' SELECT c.*, '.DeliverySpeedSql::lostHours('c').' AS lost_hours FROM by_cluster c';

        return $this->connection->createQueryBuilder()
            ->select('cluster.*')
            ->from('('.$source.')', 'cluster')
            ->setParameters(DeliverySpeedSql::parameters($companyId, $from, $to))
            ->orderBy('cluster.lost_hours', 'DESC NULLS LAST')
            ->addOrderBy('cluster.postings', 'DESC')
            ->addOrderBy('cluster.cluster_to', 'ASC')
            // +1 — узнать, что список обрезан, без COUNT(*) (CLAUDE.md §5).
            ->setMaxResults(self::LIMIT + 1);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): DeliverySpeedClusterRow
    {
        return new DeliverySpeedClusterRow(
            clusterTo: DeliverySpeedMetrics::string($row['cluster_to']),
            metrics: DeliverySpeedMetrics::fromRow($row),
            lostHours: DeliverySpeedMetrics::nullableInt($row['lost_hours']),
        );
    }
}
