<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

final readonly class SetSalesPlanCalendarResult
{
    public function __construct(public SetSalesPlanCalendarOutcome $outcome, public ?int $version = null)
    {
    }
}
