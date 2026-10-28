<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\SalesPlanning\Domain\ClaimedSalesPlanDay;

final readonly class ApproveSalesPlanResult
{
    /** @param list<ClaimedSalesPlanDay> $conflicts */
    public function __construct(
        public ApproveSalesPlanOutcome $outcome,
        public ?string $id = null,
        public ?int $version = null,
        public array $conflicts = [],
    ) {
    }
}
