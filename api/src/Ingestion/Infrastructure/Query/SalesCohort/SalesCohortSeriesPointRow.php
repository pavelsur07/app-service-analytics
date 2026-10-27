<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\SalesCohort;

final readonly class SalesCohortSeriesPointRow
{
    public function __construct(
        public string $bucketFrom,
        public string $bucketTo,
        public string $periodCoverage,
        public string $cumulativeCoverage,
        public ?SalesCohortMetricsRow $period,
        public ?SalesCohortMetricsRow $cumulative,
    ) {
    }
}
