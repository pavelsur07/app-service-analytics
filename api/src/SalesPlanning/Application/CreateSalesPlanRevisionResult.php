<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\SalesPlanning\Domain\SalesPlan;

final readonly class CreateSalesPlanRevisionResult
{
    public function __construct(public CreateSalesPlanRevisionOutcome $outcome, public ?SalesPlan $draft = null)
    {
    }
}
