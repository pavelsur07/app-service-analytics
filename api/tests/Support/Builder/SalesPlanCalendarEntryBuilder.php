<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\SalesPlanning\Domain\PlannedCalendarEvent;
use App\SalesPlanning\Domain\SalesPlan;
use App\SalesPlanning\Domain\SalesPlanCalendarEntry;
use App\SalesPlanning\Domain\SalesPlanCalendarKind;

final class SalesPlanCalendarEntryBuilder
{
    private ?PlannedCalendarEvent $event = null;

    private function __construct(private SalesPlan $plan)
    {
    }

    public static function aCalendarEntry(): self
    {
        return new self(SalesPlanBuilder::aSalesPlan()->build());
    }

    public static function forPlan(SalesPlan $plan): self
    {
        return new self($plan);
    }

    public function withEvent(PlannedCalendarEvent $event): self
    {
        $clone = clone $this;
        $clone->event = $event;

        return $clone;
    }

    public function build(): SalesPlanCalendarEntry
    {
        return SalesPlanCalendarEntry::create($this->plan, $this->event ?? new PlannedCalendarEvent(
            SalesPlanCalendarKind::Replenishment, null, $this->plan->dateFrom(), $this->plan->dateFrom(),
        ));
    }
}
