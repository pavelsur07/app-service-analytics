<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\Coverage;

/**
 * Загрузка, лежащая в очереди `failed`: какой raw-тип и за какие дни
 * она должна была загрузить.
 */
final readonly class CoverageFailure
{
    public function __construct(
        public string $reportType,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
    ) {
    }
}
