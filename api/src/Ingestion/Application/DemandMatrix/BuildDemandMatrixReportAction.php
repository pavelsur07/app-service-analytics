<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DemandMatrix;

use App\Identity\Application\Facade\CompanyConnection;
use App\Identity\Application\Facade\IdentityFacade;
use App\Ingestion\Infrastructure\Query\DemandMatrix\DemandMatrixCellQuery;
use App\Ingestion\Infrastructure\Query\DemandMatrix\DemandMatrixClustersQuery;
use App\Ingestion\Infrastructure\Query\DemandMatrix\DemandMatrixCursor;
use App\Ingestion\Infrastructure\Query\DemandMatrix\DemandMatrixLoadsQuery;
use App\Ingestion\Infrastructure\Query\DemandMatrix\DemandMatrixSkuQuery;
use App\Ingestion\Infrastructure\Query\DemandMatrix\DemandMatrixSkuRow;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Матрица спроса (docs/plan/ozon-demand-matrix-report.md): колонки,
 * страница SKU с ячейками и полнота загрузки продаж — одним снимком
 * (REPEATABLE READ): иначе загрузка между запросами дала бы ячейки,
 * не сходящиеся со строками.
 */
final readonly class BuildDemandMatrixReportAction
{
    public function __construct(
        private Connection $connection,
        private DemandMatrixClustersQuery $clusters,
        private DemandMatrixSkuQuery $skus,
        private DemandMatrixCellQuery $cells,
        private DemandMatrixLoadsQuery $loads,
        private IdentityFacade $identity,
    ) {
    }

    public function __invoke(
        string $companyId,
        \DateTimeImmutable $today,
        string $search,
        int $limit,
        ?DemandMatrixCursor $cursor = null,
    ): DemandMatrixReport {
        // Кабинеты Ozon компании, кроме отозванных самим продавцом: у
        // сломанного продажи не грузятся, и матрица об этом предупреждает
        // (как «Остатки», ADR-034). Company-scoped метод фасада Identity.
        $accounts = array_values(array_map(
            static fn (CompanyConnection $connection): string => $connection->id,
            array_filter(
                $this->identity->listConnections($companyId),
                static fn (CompanyConnection $connection): bool => 'ozon' === $connection->marketplace && 'revoked' !== $connection->state,
            ),
        ));

        $read = function (Connection $connection) use ($companyId, $today, $search, $limit, $cursor, $accounts): DemandMatrixReport {
            $skus = array_map(DemandMatrixSkuQuery::mapRow(...), self::fetch($connection, $this->skus->build($companyId, $today, $search, $limit, $cursor)));
            $hasNext = \count($skus) > $limit;
            if ($hasNext) {
                array_pop($skus);
            }

            $cellsBySku = [];
            if ([] !== $skus) {
                $page = array_map(static fn (DemandMatrixSkuRow $row): string => $row->marketplaceSku, $skus);
                foreach (self::fetch($connection, $this->cells->build($companyId, $today, $page)) as $row) {
                    $cell = DemandMatrixCellQuery::mapRow($row);
                    $cellsBySku[$cell->marketplaceSku][] = $cell;
                }
            }

            $incomplete = [] === $accounts ? 0 : DemandMatrixLoadsQuery::incompleteCount(
                $accounts,
                self::fetch($connection, $this->loads->build($companyId, $today, $accounts)),
            );
            $last = $hasNext ? $skus[array_key_last($skus)] ?? null : null;

            return new DemandMatrixReport(
                clusters: array_map(DemandMatrixClustersQuery::mapRow(...), self::fetch($connection, $this->clusters->build($companyId, $today))),
                items: array_map(
                    static fn (DemandMatrixSkuRow $sku): DemandMatrixReportItem => new DemandMatrixReportItem($sku, $cellsBySku[$sku->marketplaceSku] ?? []),
                    $skus,
                ),
                incompleteSalesAccounts: $incomplete,
                nextCursor: $last instanceof DemandMatrixSkuRow
                    ? new DemandMatrixCursor($today->format('Y-m-d'), $search, $last->orders28, $last->marketplaceSku)
                    : null,
            );
        };

        // Уже открытая транзакция (тесты, внешний сценарий): изоляцию
        // определяет владелец; ограничения планировщика — под savepoint
        // (как у «Остатков»).
        $native = $this->connection->getNativeConnection();
        if ($this->connection->isTransactionActive() || ($native instanceof \PDO && $native->inTransaction())) {
            $this->connection->createSavepoint('demand_matrix_report_guard');
            try {
                self::configurePlanner($this->connection);

                return $read($this->connection);
            } finally {
                $this->connection->rollbackSavepoint('demand_matrix_report_guard');
                $this->connection->releaseSavepoint('demand_matrix_report_guard');
            }
        }

        return $this->connection->transactional(static function (Connection $connection) use ($read): DemandMatrixReport {
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
