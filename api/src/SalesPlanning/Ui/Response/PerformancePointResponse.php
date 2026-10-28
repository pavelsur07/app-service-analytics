<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

use App\SalesPlanning\Application\SalesPlanPerformancePoint;

final readonly class PerformancePointResponse
{
    public function __construct(
        public string $bucketFrom,
        public string $bucketTo,
        public string $periodCoverage,
        public string $cumulativeCoverage,
        public PerformanceValuesResponse $period,
        public PerformanceValuesResponse $cumulative,
    ) {
    }

    public static function of(SalesPlanPerformancePoint $p): self
    {
        return new self($p->bucketFrom, $p->bucketTo, $p->periodCoverage, $p->cumulativeCoverage,
            PerformanceValuesResponse::of($p->period), PerformanceValuesResponse::of($p->cumulative));
    }
}
