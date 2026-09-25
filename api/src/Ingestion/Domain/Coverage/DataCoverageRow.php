<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\Coverage;

/**
 * Строка отчёта: статус и последний момент получения по каждому дню
 * месяца, плюс «покрыто N из M» — загружено из дней, которые уже
 * должны быть загружены.
 */
final readonly class DataCoverageRow
{
    /**
     * @param list<DataCoverageStatus>      $statuses
     * @param list<\DateTimeImmutable|null> $lastReceivedAt
     */
    public function __construct(
        public ?DataCoverageSource $source,
        public array $statuses,
        public array $lastReceivedAt,
        public int $covered,
        public int $due,
    ) {
    }
}
