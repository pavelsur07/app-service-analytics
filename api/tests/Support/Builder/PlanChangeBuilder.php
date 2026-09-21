<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Planning\Domain\DailyPlan;
use App\Planning\Domain\PlanChange;

final class PlanChangeBuilder
{
    private DailyPlan $plan;
    private ?int $oldQuantity = null;
    private int $oldVersion = 0;

    private function __construct()
    {
        $this->plan = DailyPlanBuilder::aDailyPlan()->build();
    }

    public static function aPlanChange(): self
    {
        return new self();
    }

    public function withPlan(DailyPlan $plan): self
    {
        $clone = clone $this;
        $clone->plan = $plan;

        return $clone;
    }

    public function withOldQuantity(?int $oldQuantity): self
    {
        $clone = clone $this;
        $clone->oldQuantity = $oldQuantity;

        return $clone;
    }

    public function withOldVersion(int $oldVersion): self
    {
        $clone = clone $this;
        $clone->oldVersion = $oldVersion;

        return $clone;
    }

    public function build(): PlanChange
    {
        return 0 === $this->oldVersion
            ? PlanChange::created($this->plan)
            : PlanChange::changed($this->plan, $this->oldQuantity, $this->oldVersion);
    }
}
