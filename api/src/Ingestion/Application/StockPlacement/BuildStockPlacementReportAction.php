<?php

declare(strict_types=1);

namespace App\Ingestion\Application\StockPlacement;

use App\Identity\Application\Facade\CompanyConnection;
use App\Identity\Application\Facade\IdentityFacade;
use App\Ingestion\Infrastructure\Query\StockPlacement\StockPlacementCursor;
use App\Ingestion\Infrastructure\Query\StockPlacement\StockPlacementQuery;
use App\Ingestion\Infrastructure\Query\StockPlacement\StockPlacementRow;
use App\Ingestion\Infrastructure\Query\StockPlacement\StockPlacementSummaryQuery;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Отчёт «Остатки»: сводка и страница «что куда довезти» — одним снимком
 * (REPEATABLE READ): иначе ночной прогон снимка между запросами дал бы
 * сводку, не сходящуюся со строками.
 */
final readonly class BuildStockPlacementReportAction
{
    public function __construct(
        private Connection $connection,
        private StockPlacementSummaryQuery $summary,
        private StockPlacementQuery $items,
        private IdentityFacade $identity,
    ) {
    }

    public function __invoke(
        string $companyId,
        \DateTimeImmutable $today,
        int $targetDays,
        int $leadDays,
        ?string $status,
        int $limit,
        ?StockPlacementCursor $cursor = null,
    ): StockPlacementReport {
        // Кабинеты Ozon компании, кроме отозванных самим продавцом:
        // сломанный (401/403) снимков не получает, но товар на складах у него
        // есть — его остаток неизвестен, и это надо показать, а не потерять
        // (ADR-034, тихая ошибка). Company-scoped метод фасада Identity.
        $snapshotAccounts = array_values(array_map(
            static fn (CompanyConnection $connection): string => $connection->id,
            array_filter(
                $this->identity->listConnections($companyId),
                static fn (CompanyConnection $connection): bool => 'ozon' === $connection->marketplace && 'revoked' !== $connection->state,
            ),
        ));

        $read = function (Connection $connection) use ($companyId, $today, $targetDays, $leadDays, $status, $limit, $cursor, $snapshotAccounts): StockPlacementReport {
            $summary = self::fetch($connection, $this->summary->build($companyId, $today, $targetDays, $leadDays, $snapshotAccounts))[0]
                ?? throw new \LogicException('Aggregate query returned no row.');

            $items = array_map(
                StockPlacementQuery::mapRow(...),
                self::fetch($connection, $this->items->build($companyId, $today, $targetDays, $leadDays, $status, $limit, $cursor, $snapshotAccounts)),
            );
            $hasNext = \count($items) > $limit;
            if ($hasNext) {
                array_pop($items);
            }
            $last = $hasNext ? $items[array_key_last($items)] ?? null : null;
            $snapshotDate = $summary['snapshot_date'] ?? null;

            return new StockPlacementReport(
                snapshotDate: \is_string($snapshotDate) ? $snapshotDate : null,
                completeSnapshotDays: StockPlacementQuery::int($summary['complete_days']),
                correctionApplied: true === $summary['correction_applied'],
                deficitPositions: StockPlacementQuery::int($summary['deficit_positions']),
                deficitUnits: StockPlacementQuery::int($summary['deficit_units']),
                surplusPositions: StockPlacementQuery::int($summary['surplus_positions']),
                recommendedPositions: StockPlacementQuery::int($summary['recommended_positions']),
                recommendedUnits: StockPlacementQuery::int($summary['recommended_units']),
                unknownPositions: StockPlacementQuery::int($summary['unknown_positions']),
                staleAccounts: StockPlacementQuery::int($summary['stale_accounts']),
                items: $items,
                nextCursor: $last instanceof StockPlacementRow
                    ? new StockPlacementCursor($today->format('Y-m-d'), $targetDays, $leadDays, $status, $last->priority, $last->recommended ?? -1, $last->marketplaceSku, $last->cluster)
                    : null,
            );
        };

        // Уже открытая транзакция (тесты, внешний сценарий): изоляцию
        // определяет владелец; ограничения планировщика — под savepoint
        // (ADR-020, как у «Выкупа» и «Доставки»).
        $native = $this->connection->getNativeConnection();
        if ($this->connection->isTransactionActive() || ($native instanceof \PDO && $native->inTransaction())) {
            $this->connection->createSavepoint('stock_placement_report_guard');
            try {
                self::configurePlanner($this->connection);

                return $read($this->connection);
            } finally {
                $this->connection->rollbackSavepoint('stock_placement_report_guard');
                $this->connection->releaseSavepoint('stock_placement_report_guard');
            }
        }

        return $this->connection->transactional(static function (Connection $connection) use ($read): StockPlacementReport {
            $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            self::configurePlanner($connection);

            return $read($connection);
        });
    }

    private static function configurePlanner(Connection $connection): void
    {
        $connection->executeStatement('SET LOCAL jit = off');
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
