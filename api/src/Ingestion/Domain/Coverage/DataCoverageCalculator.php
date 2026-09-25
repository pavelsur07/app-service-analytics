<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\Coverage;

/**
 * Считает отчёт о полноте данных за месяц (чистая функция, без базы).
 *
 * - День в будущем — «ещё рано».
 * - Выгрузка покрывает день — «загружено», даже если по этому дню в
 *   `failed` лежит старое сообщение: данные есть, повтор уже не нужен.
 * - Нет выгрузки, но загрузка дня лежит в `failed` — «ошибка».
 * - Иначе — «нет».
 *
 * Все даты — дни по Москве, часовому поясу площадки.
 */
final class DataCoverageCalculator
{
    /**
     * @param list<DataCoverageSource> $sources
     * @param list<CoverageDocument>   $documents
     * @param list<CoverageFailure>    $failures
     */
    public function calculate(
        array $sources,
        array $documents,
        array $failures,
        \DateTimeImmutable $monthStart,
        \DateTimeImmutable $today,
    ): DataCoverage {
        $days = self::days($monthStart);
        $todayKey = $today->format('Y-m-d');

        $rows = [];
        foreach ($sources as $source) {
            $covered = $this->coveredDays($source, $documents);
            $failed = $this->failedDays($source, $failures);

            $statuses = [];
            $received = [];
            foreach ($days as $day) {
                $key = $day->format('Y-m-d');
                $received[] = $covered[$key] ?? null;
                $statuses[] = match (true) {
                    $key > $todayKey => DataCoverageStatus::Pending,
                    isset($covered[$key]) => DataCoverageStatus::Loaded,
                    isset($failed[$key]) => DataCoverageStatus::Failed,
                    default => DataCoverageStatus::Missing,
                };
            }

            $rows[] = self::row($source, $statuses, $received);
        }

        return new DataCoverage($days, self::total($days, $rows), $rows);
    }

    /**
     * День → последний момент получения выгрузки, покрывающей день.
     *
     * @param list<CoverageDocument> $documents
     *
     * @return array<string, \DateTimeImmutable>
     */
    private function coveredDays(DataCoverageSource $source, array $documents): array
    {
        $covered = [];
        foreach ($documents as $document) {
            if ($document->reportType !== $source->reportType) {
                continue;
            }

            $from = $document->period;
            $to = $from;
            if (DataCoverageSource::KindRange === $source->kind) {
                $byLength = $from->modify('+'.($source->rangeDays - 1).' days');
                $received = new \DateTimeImmutable($document->lastReceivedAt->format('Y-m-d'), $from->getTimezone());
                $byReceipt = $received->modify('-'.$source->rangeEndLagDays.' days');
                $to = $byLength < $byReceipt ? $byLength : $byReceipt;
            }

            for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
                $key = $day->format('Y-m-d');
                if (!isset($covered[$key]) || $covered[$key] < $document->lastReceivedAt) {
                    $covered[$key] = $document->lastReceivedAt;
                }
            }
        }

        return $covered;
    }

    /**
     * @param list<CoverageFailure> $failures
     *
     * @return array<string, true>
     */
    private function failedDays(DataCoverageSource $source, array $failures): array
    {
        $failed = [];
        foreach ($failures as $failure) {
            if ($failure->reportType !== $source->reportType) {
                continue;
            }

            for ($day = $failure->from; $day <= $failure->to; $day = $day->modify('+1 day')) {
                $failed[$day->format('Y-m-d')] = true;
            }
        }

        return $failed;
    }

    /**
     * «Итого»: худший статус дня по всем строкам.
     *
     * @param list<\DateTimeImmutable> $days
     * @param list<DataCoverageRow>    $rows
     */
    private static function total(array $days, array $rows): DataCoverageRow
    {
        $statuses = [];
        $received = [];
        foreach (array_keys($days) as $index) {
            $worst = null;
            $latest = null;
            foreach ($rows as $row) {
                $status = $row->statuses[$index];
                if (null === $worst || $status->severity() > $worst->severity()) {
                    $worst = $status;
                }
                $at = $row->lastReceivedAt[$index];
                if (null !== $at && (null === $latest || $at > $latest)) {
                    $latest = $at;
                }
            }
            $statuses[] = $worst ?? DataCoverageStatus::Missing;
            $received[] = $latest;
        }

        return self::row(null, $statuses, $received);
    }

    /**
     * @param list<DataCoverageStatus>      $statuses
     * @param list<\DateTimeImmutable|null> $received
     */
    private static function row(?DataCoverageSource $source, array $statuses, array $received): DataCoverageRow
    {
        $due = \count(array_filter($statuses, static fn (DataCoverageStatus $status): bool => DataCoverageStatus::Pending !== $status));
        $covered = \count(array_filter($statuses, static fn (DataCoverageStatus $status): bool => DataCoverageStatus::Loaded === $status));

        return new DataCoverageRow($source, $statuses, $received, $covered, $due);
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    private static function days(\DateTimeImmutable $monthStart): array
    {
        $days = [];
        $end = $monthStart->modify('last day of this month');
        for ($day = $monthStart; $day <= $end; $day = $day->modify('+1 day')) {
            $days[] = $day;
        }

        return $days;
    }
}
