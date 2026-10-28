<?php

declare(strict_types=1);

namespace App\SalesPlanning\Infrastructure\Query;

final readonly class SalesPlanPerformancePointRow
{
    public function __construct(
        public string $bucketFrom,
        public string $bucketTo,
        public int $periodQuantity,
        public int $periodRevenueMinor,
        public int $cumulativeQuantity,
        public int $cumulativeRevenueMinor,
    ) {
    }
}
