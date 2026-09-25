<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\Coverage;

/**
 * Выгрузки одного raw-типа с одним `period`: день начала (или сам день)
 * и последний момент получения — по московскому дню.
 */
final readonly class CoverageDocument
{
    public function __construct(
        public string $reportType,
        public \DateTimeImmutable $period,
        public \DateTimeImmutable $lastReceivedAt,
    ) {
    }
}
