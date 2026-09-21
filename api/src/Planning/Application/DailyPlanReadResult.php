<?php

declare(strict_types=1);

namespace App\Planning\Application;

use App\Planning\Domain\DailyPlanMutationOutcome;

final readonly class DailyPlanReadResult
{
    /** @param list<DailyPlanItem> $items */
    public function __construct(public DailyPlanMutationOutcome $outcome, public array $items)
    {
    }
}
