<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Persistence;

use App\Ingestion\Domain\MarketplaceDayLoadRepository;
use Doctrine\DBAL\Connection;

/**
 * DBAL, не ORM (CLAUDE.md §6): upsert отметки по ключу с перехватом
 * конфликта на вставке (§4) — «найти и, если нет, записать» здесь нет.
 * Диапазон — одним запросом через generate_series, не циклом запросов
 * (§6).
 *
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

    public function markCompleted(string $companyId, string $marketplaceAccountId, string $reportType, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO marketplace_day_load (company_id, marketplace_account_id, report_type, period, completed_at)
            SELECT :companyId, :marketplaceAccountId, :reportType, day::date, :at
            FROM generate_series(CAST(:from AS date), CAST(:to AS date), interval '1 day') AS day
            ON CONFLICT (company_id, marketplace_account_id, report_type, period)
            DO UPDATE SET completed_at = EXCLUDED.completed_at, failed_at = NULL, failure = NULL
            SQL, self::parameters($companyId, $marketplaceAccountId, $reportType, $from, $to, $at));
    }

    public function markFailed(string $companyId, string $marketplaceAccountId, string $reportType, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $at, string $failure): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO marketplace_day_load (company_id, marketplace_account_id, report_type, period, failed_at, failure)
            SELECT :companyId, :marketplaceAccountId, :reportType, day::date, :at, :failure
            FROM generate_series(CAST(:from AS date), CAST(:to AS date), interval '1 day') AS day
            ON CONFLICT (company_id, marketplace_account_id, report_type, period)
            DO UPDATE SET failed_at = EXCLUDED.failed_at, failure = EXCLUDED.failure
            SQL, [
            ...self::parameters($companyId, $marketplaceAccountId, $reportType, $from, $to, $at),
            'failure' => mb_substr($failure, 0, self::FAILURE_LENGTH),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function parameters(string $companyId, string $marketplaceAccountId, string $reportType, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $at): array
    {
        $first = $from->format('Y-m-d');
        $last = $to->format('Y-m-d');
        $days = (int) (new \DateTimeImmutable($first))->diff(new \DateTimeImmutable($last))->format('%r%a') + 1;
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new \InvalidArgumentException("Отметка загрузки на {$days} дн. ({$first}..{$last}) — диапазон пуст или длиннее ".self::MAX_DAYS.' дней.');
        }

        return [
            'companyId' => $companyId,
            'marketplaceAccountId' => $marketplaceAccountId,
            'reportType' => $reportType,
            'from' => $first,
            'to' => $last,
            'at' => $at->format('Y-m-d H:i:sP'),
        ];
    }
}
