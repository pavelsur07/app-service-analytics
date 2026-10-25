<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Persistence;

use App\Ingestion\Infrastructure\ServiceCoverage\ServiceCoverageSnapshot;
use App\Ingestion\Infrastructure\ServiceCoverage\ServiceCoverageState;

/**
 * Сохранённая сводка покрытия: исход последней попытки и последняя
 * удачная сводка (если была).
 */
final readonly class CapturedServiceCoverage
{
    public function __construct(
        public ServiceCoverageState $state,
        public \DateTimeImmutable $attemptedAt,
        public ?\DateTimeImmutable $succeededAt,
        public ?ServiceCoverageSnapshot $lastSuccess,
    ) {
    }
}
