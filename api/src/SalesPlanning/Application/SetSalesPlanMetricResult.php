<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

final readonly class SetSalesPlanMetricResult
{
    public function __construct(public SetSalesPlanMetricOutcome $outcome, public ?int $version = null)
    {
    }
}
