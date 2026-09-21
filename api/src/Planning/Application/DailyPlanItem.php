<?php

declare(strict_types=1);

namespace App\Planning\Application;

use App\Planning\Domain\DailyPlan;

final readonly class DailyPlanItem
{
    public function __construct(public string $date, public ?int $quantity, public int $version)
    {
    }

    public static function fromPlan(DailyPlan $plan): self
    {
        return new self($plan->businessDate()->format('Y-m-d'), $plan->quantity(), $plan->version());
    }
}
