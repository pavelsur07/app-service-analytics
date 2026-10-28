<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Persistence;

use App\Ingestion\Domain\DayLoadMark;
use App\Ingestion\Domain\FactCaptureOrigin;
use App\Ingestion\Domain\MarketplaceDayLoadRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * DBAL, не ORM (CLAUDE.md §6): upsert отметок по ключу с перехватом
 * конфликта на вставке (§4) — «найти и, если нет, записать» здесь нет.
 * Все отметки прогона и все их дни — одним запросом: список отметок
 * разворачивается в дни через generate_series, без цикла запросов (§6).
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

    public function mark(string $companyId, string $marketplaceAccountId, array $marks, \DateTimeImmutable $at, FactCaptureOrigin $origin = FactCaptureOrigin::Unknown): void
    {
        if ([] === $marks) {
            return;
        }

        $values = [];
        $params = ['companyId' => $companyId, 'marketplaceAccountId' => $marketplaceAccountId, 'markedAt' => $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'captureOrigin' => $origin->value];
        /** @var array<string, list<array{int, int}>> $ranges */
        $ranges = [];
        foreach ($marks as $i => $mark) {
            [$first, $last] = self::range($mark);
            foreach ($ranges[$mark->reportType] ?? [] as [$otherFirst, $otherLast]) {
                if ($first <= $otherLast && $otherFirst <= $last) {
                    throw new \InvalidArgumentException("Отметки типа {$mark->reportType} пересекаются по дням — исход дня неоднозначен.");
                }
            }
            $ranges[$mark->reportType][] = [$first, $last];

            $values[] = "(:reportType{$i}, CAST(:from{$i} AS date), CAST(:to{$i} AS date), CAST(:completedAt{$i} AS timestamp), CAST(:failedAt{$i} AS timestamp), CAST(:failure{$i} AS varchar), CAST(:receiptId{$i} AS uuid), CAST(:receiptStatus{$i} AS varchar))";
            $params["receiptId{$i}"] = Uuid::v7()->toRfc4122();
            $params["receiptStatus{$i}"] = null === $mark->failure ? 'completed' : 'failed';
            $params["reportType{$i}"] = $mark->reportType;
            $params["from{$i}"] = $mark->from->format('Y-m-d');
            $params["to{$i}"] = $mark->to->format('Y-m-d');
            $params["completedAt{$i}"] = null === $mark->failure ? $at->format('Y-m-d H:i:sP') : null;
            $params["failedAt{$i}"] = null === $mark->failure ? null : $at->format('Y-m-d H:i:sP');
            $params["failure{$i}"] = null === $mark->failure ? null : mb_substr($mark->failure, 0, self::FAILURE_LENGTH);
        }

        $valuesSql = implode(', ', $values);
        // Успех: completed_at — новое время, отказ стёрт. Отказ:
        // completed_at прежний (COALESCE), failed_at и причина — новые.
        $this->connection->executeStatement(<<<SQL
            WITH marks (report_type, first_day, last_day, completed_at, failed_at, failure, receipt_id, status) AS (
                VALUES {$valuesSql}
            ), current_marks AS (
            INSERT INTO marketplace_day_load (company_id, marketplace_account_id, report_type, period, completed_at, failed_at, failure)
            SELECT :companyId, :marketplaceAccountId, m.report_type, day::date, m.completed_at, m.failed_at, m.failure
            FROM marks m
            CROSS JOIN LATERAL generate_series(m.first_day, m.last_day, interval '1 day') AS day
            ON CONFLICT (company_id, marketplace_account_id, report_type, period)
            DO UPDATE SET completed_at = COALESCE(EXCLUDED.completed_at, marketplace_day_load.completed_at),
                          failed_at = EXCLUDED.failed_at,
                          failure = EXCLUDED.failure
            RETURNING company_id
            )
            INSERT INTO marketplace_day_load_receipt
                (company_id, marketplace_account_id, report_type, date_from, date_to, marked_at, status, id, capture_version, capture_origin)
            SELECT CAST(:companyId AS uuid), CAST(:marketplaceAccountId AS uuid),
                   m.report_type, m.first_day, m.last_day, CAST(:markedAt AS timestamp), m.status, m.receipt_id, 'accepted_state_v1', :captureOrigin
            FROM marks m
            WHERE true
            ON CONFLICT (company_id, marketplace_account_id, report_type, date_from, date_to, marked_at, status)
            DO NOTHING
            SQL, $params);
    }

    /**
     * Дни отметки номерами дней — для проверки потолка и пересечений.
     *
     * @return array{int, int}
     */
    private static function range(DayLoadMark $mark): array
    {
        $first = self::dayNumber($mark->from);
        $last = self::dayNumber($mark->to);
        $days = $last - $first + 1;
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new \InvalidArgumentException(\sprintf('Отметка загрузки %s на %d дн. (%s..%s) — диапазон пуст или длиннее %d дней.', $mark->reportType, $days, $mark->from->format('Y-m-d'), $mark->to->format('Y-m-d'), self::MAX_DAYS));
        }

        return [$first, $last];
    }

    /** Номер календарного дня от эпохи — по дате, без часового пояса. */
    private static function dayNumber(\DateTimeImmutable $day): int
    {
        return intdiv((new \DateTimeImmutable($day->format('Y-m-d'), new \DateTimeZone('UTC')))->getTimestamp(), 86_400);
    }
}
