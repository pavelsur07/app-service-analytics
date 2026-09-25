<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\Coverage;

/**
 * Отчёт о полноте данных кабинета за месяц: дни, «Итого» и строки
 * источников.
 */
final readonly class DataCoverage
{
    /**
     * @param list<\DateTimeImmutable> $days
     * @param list<DataCoverageRow>    $rows
     */
    public function __construct(
        public array $days,
        public DataCoverageRow $total,
        public array $rows,
    ) {
    }
}
