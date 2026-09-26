<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Localization;

use App\Ingestion\Infrastructure\Query\Localization\LocalizationClusterQuery;
use App\Ingestion\Infrastructure\Query\Localization\LocalizationMetrics;
use App\Ingestion\Infrastructure\Query\Localization\LocalizationSkuCursor;
use App\Ingestion\Infrastructure\Query\Localization\LocalizationSkuQuery;
use App\Ingestion\Infrastructure\Query\Localization\LocalizationSkuRow;
use App\Ingestion\Infrastructure\Query\Localization\LocalizationSummaryQuery;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Отчёт «Локализация»: сводка, кластеры доставки и страница SKU × кластер.
 * Три запроса читают один снимок (REPEATABLE READ): иначе синхронизация
 * между ними дала бы сводку, не сходящуюся с таблицами.
 */
final readonly class BuildLocalizationReportAction
{
    public function __construct(
        private Connection $connection,
        private LocalizationSummaryQuery $summary,
        private LocalizationClusterQuery $clusters,
        private LocalizationSkuQuery $skus,
    ) {
    }

    public function __invoke(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $days,
        int $limit,
        ?LocalizationSkuCursor $cursor = null,
    ): LocalizationReport {
        $read = function (Connection $connection) use ($companyId, $from, $to, $days, $limit, $cursor): LocalizationReport {
            $summaryRows = self::fetch($connection, $this->summary->build($companyId, $from, $to));
            $summaryRow = $summaryRows[0] ?? throw new \LogicException('Aggregate query returned no row.');

            $clusters = array_map(
                LocalizationClusterQuery::mapRow(...),
                self::fetch($connection, $this->clusters->build($companyId, $from, $to)),
            );
            $clustersTruncated = \count($clusters) > LocalizationClusterQuery::LIMIT;
            if ($clustersTruncated) {
                array_pop($clusters);
            }

            $skus = array_map(
                LocalizationSkuQuery::mapRow(...),
                self::fetch($connection, $this->skus->build($companyId, $from, $to, $limit, $cursor)),
            );
            $hasNext = \count($skus) > $limit;
            if ($hasNext) {
                array_pop($skus);
            }
            $last = $hasNext ? $skus[array_key_last($skus)] ?? null : null;

            return new LocalizationReport(
                summary: LocalizationMetrics::fromRow($summaryRow),
                clusters: $clusters,
                clustersTruncated: $clustersTruncated,
                skus: $skus,
                nextCursor: $last instanceof LocalizationSkuRow
                    ? new LocalizationSkuCursor($days, $to, $last->metrics->nonlocalQuantity, $last->marketplaceSku, $last->clusterTo)
                    : null,
            );
        };

        // Уже открытую транзакцию (интеграционные тесты, внешний сценарий)
        // не трогаем: уровень её изоляции определяет владелец, а SET
        // TRANSACTION после его запросов PostgreSQL запрещает.
        $native = $this->connection->getNativeConnection();
        if ($this->connection->isTransactionActive() || ($native instanceof \PDO && $native->inTransaction())) {
            return $read($this->connection);
        }

        return $this->connection->transactional(static function (Connection $connection) use ($read): LocalizationReport {
            $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $connection->executeStatement("SET LOCAL statement_timeout = '5s'");

            return $read($connection);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fetch(Connection $connection, QueryBuilder $query): array
    {
        return $connection->fetchAllAssociative($query->getSQL(), $query->getParameters(), $query->getParameterTypes());
    }
}
