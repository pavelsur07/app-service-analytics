<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DeliverySpeed;

use App\Ingestion\Infrastructure\Query\DeliverySpeed\DeliverySpeedBuyoutQuery;
use App\Ingestion\Infrastructure\Query\DeliverySpeed\DeliverySpeedClusterQuery;
use App\Ingestion\Infrastructure\Query\DeliverySpeed\DeliverySpeedMetrics;
use App\Ingestion\Infrastructure\Query\DeliverySpeed\DeliverySpeedRouteQuery;
use App\Ingestion\Infrastructure\Query\DeliverySpeed\DeliverySpeedSkuCursor;
use App\Ingestion\Infrastructure\Query\DeliverySpeed\DeliverySpeedSkuQuery;
use App\Ingestion\Infrastructure\Query\DeliverySpeed\DeliverySpeedSkuRow;
use App\Ingestion\Infrastructure\Query\DeliverySpeed\DeliverySpeedSummaryQuery;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Отчёт «Скорость доставки»: сводка, кластеры, маршруты, страница
 * «что довезти первым» и выкуп по скорости — одним снимком (REPEATABLE READ),
 * иначе синхронизация между запросами дала бы несходящиеся цифры.
 */
final readonly class BuildDeliverySpeedReportAction
{
    public function __construct(
        private Connection $connection,
        private DeliverySpeedSummaryQuery $summary,
        private DeliverySpeedClusterQuery $clusters,
        private DeliverySpeedRouteQuery $routes,
        private DeliverySpeedSkuQuery $skus,
        private DeliverySpeedBuyoutQuery $buyout,
    ) {
    }

    public function __invoke(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $days,
        int $limit,
        ?DeliverySpeedSkuCursor $cursor = null,
    ): DeliverySpeedReport {
        $read = function (Connection $connection) use ($companyId, $from, $to, $days, $limit, $cursor): DeliverySpeedReport {
            $summaryRow = self::fetch($connection, $this->summary->build($companyId, $from, $to))[0]
                ?? throw new \LogicException('Aggregate query returned no row.');

            $clusters = array_map(
                DeliverySpeedClusterQuery::mapRow(...),
                self::fetch($connection, $this->clusters->build($companyId, $from, $to)),
            );
            $clustersTruncated = \count($clusters) > DeliverySpeedClusterQuery::LIMIT;
            if ($clustersTruncated) {
                array_pop($clusters);
            }

            $routes = array_map(
                DeliverySpeedRouteQuery::mapRow(...),
                self::fetch($connection, $this->routes->build($companyId, $from, $to)),
            );
            $routesTruncated = \count($routes) > DeliverySpeedRouteQuery::LIMIT;
            if ($routesTruncated) {
                array_pop($routes);
            }

            $skus = array_map(
                DeliverySpeedSkuQuery::mapRow(...),
                self::fetch($connection, $this->skus->build($companyId, $from, $to, $limit, $cursor)),
            );
            $hasNext = \count($skus) > $limit;
            if ($hasNext) {
                array_pop($skus);
            }
            $last = $hasNext ? $skus[array_key_last($skus)] ?? null : null;

            return new DeliverySpeedReport(
                periodPostings: DeliverySpeedMetrics::int($summaryRow['period_postings']),
                summary: DeliverySpeedMetrics::fromRow($summaryRow),
                clusters: $clusters,
                clustersTruncated: $clustersTruncated,
                routes: $routes,
                routesTruncated: $routesTruncated,
                skus: $skus,
                nextCursor: $last instanceof DeliverySpeedSkuRow
                    ? new DeliverySpeedSkuCursor($days, $to, $last->lostHours, $last->marketplaceSku, $last->clusterTo)
                    : null,
                buyoutBySpeed: DeliverySpeedBuyoutQuery::mapRows(
                    self::fetch($connection, $this->buyout->build($companyId, $from, $to)),
                ),
            );
        };

        // Уже открытая транзакция (интеграционные тесты, внешний сценарий):
        // уровень её изоляции определяет владелец, а SET TRANSACTION после
        // его запросов PostgreSQL запрещает. Ограничения планировщика
        // и таймаут всё равно действуют — под savepoint, чей откат снимает
        // и SET LOCAL, и ошибку таймаута (ADR-020, как у «Выкупа»).
        $native = $this->connection->getNativeConnection();
        if ($this->connection->isTransactionActive() || ($native instanceof \PDO && $native->inTransaction())) {
            $this->connection->createSavepoint('delivery_speed_report_guard');
            try {
                self::configurePlanner($this->connection);

                return $read($this->connection);
            } finally {
                $this->connection->rollbackSavepoint('delivery_speed_report_guard');
                $this->connection->releaseSavepoint('delivery_speed_report_guard');
            }
        }

        return $this->connection->transactional(static function (Connection $connection) use ($read): DeliverySpeedReport {
            $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            self::configurePlanner($connection);

            return $read($connection);
        });
    }

    /**
     * buyout_outcome — тяжёлое представление; те же ограничения
     * планировщика, что у отчёта «Выкуп» (BuildBuyoutRateReportAction).
     */
    private static function configurePlanner(Connection $connection): void
    {
        $connection->executeStatement('SET LOCAL jit = off');
        $connection->executeStatement('SET LOCAL enable_nestloop = off');
        $connection->executeStatement("SET LOCAL statement_timeout = '5s'");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fetch(Connection $connection, QueryBuilder $query): array
    {
        return $connection->fetchAllAssociative($query->getSQL(), $query->getParameters(), $query->getParameterTypes());
    }
}
