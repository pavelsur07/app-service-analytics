<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain;

final readonly class CalendarSelection
{
    /** @param list<PeriodRange> $periods */
    public function __construct(public array $periods = [], public ?int $recentMonths = null, public ?PeriodRange $previousYear = null)
    {
        if (1 !== (int) ([] !== $periods) + (int) (null !== $recentMonths) + (int) (null !== $previousYear)) {
            throw new \InvalidArgumentException('invalid_selection');
        }
    }

    /** @return list<PeriodRange> */
    public function resolve(ResearchCalendar $calendar, \DateTimeImmutable $at): array
    {
        if (null !== $this->recentMonths) {
            return [$calendar->lastClosedMonths($this->recentMonths, $at)];
        }
        if (null !== $this->previousYear) {
            return $calendar->withPreviousYear($this->previousYear);
        }

        return $this->periods;
    }
}
