<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;

/**
 * Подключения всех компаний с полным снимком остатков после $since —
 * межарендаторное чтение сторожа свежести (CLAUDE.md §1, операционная
 * системная задача; узкий слой Deptrac IngestionOperationalQuery, виден
 * только NotifyStaleAccountsAction). Признак жизни снимочного факта —
 * завершённый полный прогон, а не raw (ADR-034, CLAUDE.md «Наблюдаемость»).
 *
 * Индекс ведущим столбцом — started_at (idx_stock_snapshot_run_started_at):
 * у межарендаторного чтения компании нет, отбор идёт по времени
 * («индекс следует за запросом», CLAUDE.md §1).
 */
final readonly class RecentStockSnapshotAccountsQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function build(\DateTimeImmutable $since): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('company_id', 'marketplace_account_id')
            ->from('stock_snapshot_run')
            ->where('started_at >= :since')
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->groupBy('company_id')
            ->addGroupBy('marketplace_account_id')
            ->setMaxResults(RecentlyIngestedAccountsQuery::MAX_ACCOUNTS + 1);
    }
}
