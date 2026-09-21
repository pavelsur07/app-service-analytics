<?php

declare(strict_types=1);

namespace App\Planning\Application;

use App\Planning\Domain\DailyPlanMutationOutcome;

final readonly class DailyPlanMutationResult
{
    public function __construct(public DailyPlanMutationOutcome $outcome, public ?DailyPlanItem $current)
    {
    }
}
