<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Итог прогона одной выгрузки на её дни (ADR-038): успех или отказ
 * с причиной, на каждый день `from..to` включительно.
 */
final readonly class DayLoadMark
{
    private function __construct(
        public string $reportType,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public ?string $failure,
    ) {
    }

    /** Все страницы выгрузки записаны. */
    public static function completed(string $reportType, \DateTimeImmutable $from, \DateTimeImmutable $to): self
    {
        return new self($reportType, $from, $to, null);
    }

    /** Прогон отказал после начала — часть страниц могла записаться. */
    public static function failed(string $reportType, \DateTimeImmutable $from, \DateTimeImmutable $to, string $failure): self
    {
        return new self($reportType, $from, $to, $failure);
    }
}
