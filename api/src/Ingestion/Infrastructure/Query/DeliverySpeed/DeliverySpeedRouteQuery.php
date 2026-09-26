<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\DeliverySpeed;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/** Маршруты «кластер отгрузки → кластер доставки»: сколько и как долго. */
final readonly class DeliverySpeedRouteQuery
{
    public const int LIMIT = 50;

    public function __construct(private Connection $connection)
    {
    }

    public function build(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        $source = 'WITH '.DeliverySpeedSql::timedCte()
            .' SELECT cluster_from, cluster_to, bool_and(is_local) AS local, '.DeliverySpeedSql::metricsSelect()
            .' FROM timed WHERE live AND has_clusters GROUP BY cluster_from, cluster_to';

        return $this->connection->createQueryBuilder()
            ->select('route.*')
            ->from('('.$source.')', 'route')
            ->setParameters(DeliverySpeedSql::parameters($companyId, $from, $to))
            ->orderBy('route.postings', 'DESC')
            ->addOrderBy('route.cluster_from', 'ASC')
            ->addOrderBy('route.cluster_to', 'ASC')
            ->setMaxResults(self::LIMIT + 1);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): DeliverySpeedRouteRow
    {
        return new DeliverySpeedRouteRow(
            clusterFrom: DeliverySpeedMetrics::string($row['cluster_from']),
            clusterTo: DeliverySpeedMetrics::string($row['cluster_to']),
            local: true === $row['local'],
            metrics: DeliverySpeedMetrics::fromRow($row),
        );
    }
}
