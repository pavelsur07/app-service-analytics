<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\Coverage;

/**
 * Загрузка, лежащая в очереди `failed`: какой raw-тип и за какие дни
 * она должна была загрузить и когда упала (`null` — момент неизвестен:
 * тогда любая выгрузка дня сильнее ошибки).
 */
final readonly class CoverageFailure
{
    public function __construct(
        public string $reportType,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public ?\DateTimeImmutable $failedAt = null,
    ) {
    }
}
