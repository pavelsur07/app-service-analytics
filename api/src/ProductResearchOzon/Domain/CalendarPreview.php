<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain;

final readonly class CalendarPreview
{
    public const string Timezone = 'Europe/Moscow';

    /** @param list<PeriodRange> $periods
     * @param list<MonthSlice> $months
     */
    public function __construct(public array $periods, public array $months, public string $calendarDate, public string $summary)
    {
    }

    public function suggestedName(string $categoryPath): string
    {
        $parts = explode('/', $categoryPath);
        $name = trim($parts[array_key_last($parts)]).' · '.$this->summary;

        return mb_strlen($name) <= 200 ? $name : mb_substr($name, 0, 199).'…';
    }
}
