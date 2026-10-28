<?php

declare(strict_types=1);

namespace App\SalesPlanning\Infrastructure\Query;

final readonly class SalesPlanPerformanceSkuRow
{
    /** @param list<SalesPlanPerformancePointRow> $points */
    public function __construct(public string $marketplaceSku, public array $points)
    {
    }
}
