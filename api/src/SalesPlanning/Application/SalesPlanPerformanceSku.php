<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

final readonly class SalesPlanPerformanceSku
{
    /** @param list<SalesPlanPerformancePoint> $points */
    public function __construct(public string $marketplaceSku, public array $points)
    {
    }
}
