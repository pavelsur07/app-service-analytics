<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

/** Incomplete coverage suppresses the entire component object. */
final readonly class SalesCohortPoint
{
    public function __construct(
        public string $bucketFrom,
        public string $bucketTo,
        public string $periodCoverage,
        public string $cumulativeCoverage,
        public ?SalesCohortComponents $period,
        public ?SalesCohortComponents $cumulative,
    ) {
    }
}
