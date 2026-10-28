<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\SalesPlanning\Domain\SalesPlanStatus;

final readonly class CloseSalesPlanResult
{
    public function __construct(public CloseSalesPlanOutcome $outcome, public ?string $id = null, public ?int $version = null, public ?SalesPlanStatus $status = null)
    {
    }
}
