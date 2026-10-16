<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Persistence;

use App\Ingestion\Domain\MarketplaceDayLoadRepository;
use Doctrine\DBAL\Connection;

/**
 * DBAL, не ORM (CLAUDE.md §6): upsert отметки по ключу с перехватом
 * конфликта на вставке (§4) — «найти и, если нет, записать» здесь нет.
 * Успех стирает отметку отказа, отказ успех не трогает: день отклонён,
 * пока `failed_at` не пуст (`DayLoadSql`). Какой прогон последний, решает
 * порядок записей в базу, а не сравнение времён — у колонок точность
 * до секунды, и успех и отказ в одну секунду по времени неразличимы.
 */
final readonly class DoctrineMarketplaceDayLoadWriter implements MarketplaceDayLoadRepository
{
    /** Потолок колонки `failure`. */
    private const int FAILURE_LENGTH = 500;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function markCompleted(string $companyId, string $marketplaceAccountId, string $reportType, \DateTimeImmutable $period, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO marketplace_day_load (company_id, marketplace_account_id, report_type, period, completed_at)
            VALUES (:companyId, :marketplaceAccountId, :reportType, :period, :at)
            ON CONFLICT (company_id, marketplace_account_id, report_type, period)
            DO UPDATE SET completed_at = EXCLUDED.completed_at, failed_at = NULL, failure = NULL
            SQL, [
            'companyId' => $companyId,
            'marketplaceAccountId' => $marketplaceAccountId,
            'reportType' => $reportType,
            'period' => $period->format('Y-m-d'),
            'at' => $at->format('Y-m-d H:i:sP'),
        ]);
    }

    public function markFailed(string $companyId, string $marketplaceAccountId, string $reportType, \DateTimeImmutable $period, \DateTimeImmutable $at, string $failure): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO marketplace_day_load (company_id, marketplace_account_id, report_type, period, failed_at, failure)
            VALUES (:companyId, :marketplaceAccountId, :reportType, :period, :at, :failure)
            ON CONFLICT (company_id, marketplace_account_id, report_type, period)
            DO UPDATE SET failed_at = EXCLUDED.failed_at, failure = EXCLUDED.failure
            SQL, [
            'companyId' => $companyId,
            'marketplaceAccountId' => $marketplaceAccountId,
            'reportType' => $reportType,
            'period' => $period->format('Y-m-d'),
            'at' => $at->format('Y-m-d H:i:sP'),
            'failure' => mb_substr($failure, 0, self::FAILURE_LENGTH),
        ]);
    }
}
