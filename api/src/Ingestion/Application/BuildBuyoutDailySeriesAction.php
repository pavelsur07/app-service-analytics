<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Ingestion\Infrastructure\Query\BuyoutDailyQuery;
use App\Ingestion\Infrastructure\Query\BuyoutDailyRow;
use Doctrine\DBAL\Connection;

/** Выполняет bounded daily aggregate с защитой от плохого cold-import plan. */
final readonly class BuildBuyoutDailySeriesAction
{
    public function __construct(
        private Connection $connection,
        private BuyoutDailyQuery $query,
    ) {
    }

    /** @return list<BuyoutDailyRow> */
    public function __invoke(
        string $companyId,
        string $marketplaceSku,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $asOf,
    ): array {
        $read = function (Connection $connection) use ($companyId, $marketplaceSku, $from, $to, $asOf): array {
            $query = $this->query->build($companyId, $marketplaceSku, $from, $to, $asOf);
            $rows = $connection->fetchAllAssociative(
                $query->getSQL(),
                $query->getParameters(),
                $query->getParameterTypes(),
            );

            return array_map(BuyoutDailyQuery::mapRow(...), $rows);
        };

        $nativeConnection = $this->connection->getNativeConnection();
        if (
            $this->connection->isTransactionActive()
            || ($nativeConnection instanceof \PDO && $nativeConnection->inTransaction())
        ) {
            $this->connection->createSavepoint('buyout_daily_series_guard');
            try {
                self::configurePlanner($this->connection);

                return $read($this->connection);
            } finally {
                $this->connection->rollbackSavepoint('buyout_daily_series_guard');
                $this->connection->releaseSavepoint('buyout_daily_series_guard');
            }
        }

        return $this->connection->transactional(
            static function (Connection $connection) use ($read): array {
                $connection->executeStatement('SET TRANSACTION READ ONLY');
                self::configurePlanner($connection);

                return $read($connection);
            },
        );
    }

    private static function configurePlanner(Connection $connection): void
    {
        $connection->executeStatement('SET LOCAL jit = off');
        $connection->executeStatement('SET LOCAL enable_nestloop = off');
        $connection->executeStatement("SET LOCAL statement_timeout = '5s'");
    }
}
