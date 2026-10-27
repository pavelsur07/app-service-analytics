<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

final readonly class SalesPlanPerformancePoint
{
    public function __construct(
        public string $bucketFrom,
        public string $bucketTo,
        public string $periodCoverage,
        public string $cumulativeCoverage,
        public SalesPlanPerformanceValues $period,
        public SalesPlanPerformanceValues $cumulative,
    ) {
    }
}
