<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Application;

use App\ProductResearchOzon\Domain\CalendarPreview;
use App\ProductResearchOzon\Domain\CalendarSelection;
use App\ProductResearchOzon\Domain\CategoryLookup;
use App\ProductResearchOzon\Domain\CategoryReference;
use App\ProductResearchOzon\Domain\ResearchCalendar;
use App\ProductResearchOzon\Domain\ResearchProblem;
use Psr\Clock\ClockInterface;

final readonly class ResearchPreparation
{
    public function __construct(private ResearchCalendar $calendar, private CategoryLookup $categories, private ClockInterface $clock)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    public function preview(CalendarSelection $selection, bool $includeCurrent, \DateTimeImmutable $at, ?string $expectedDate = null): CalendarPreview
    {
        $currentDate = $at->setTimezone(new \DateTimeZone(CalendarPreview::Timezone))->format('Y-m-d');
        if (null !== $expectedDate && $expectedDate !== $currentDate) {
            throw new ResearchProblem('preview_outdated');
        }

        return $this->calendar->preview($selection->resolve($this->calendar, $at), $includeCurrent, $at);
    }

    public function category(string $companyId, string $path): CategoryReference
    {
        return $this->categories->resolve($companyId, $path) ?? throw new \InvalidArgumentException('unknown_category');
    }
}
