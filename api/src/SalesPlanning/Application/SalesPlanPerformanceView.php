<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

final readonly class SalesPlanPerformanceView
{
    /** @param list<SalesPlanPerformanceSku> $skus */
    public function __construct(
        public string $currentPlanId,
        public string $baselinePlanId,
        public string $metric,
        public string $currency,
        public string $accumulationFrom,
        public string $from,
        public string $to,
        public string $groupBy,
        public \DateTimeImmutable $calculatedAt,
        public string $methodology,
        public string $ordersCoverage,
        public string $returnsCoverage,
        public array $skus,
        public ?string $nextCursor,
        public string $summaryScope = 'sku_page',
    ) {
    }
}
