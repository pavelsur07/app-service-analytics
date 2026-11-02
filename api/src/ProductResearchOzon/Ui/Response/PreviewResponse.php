<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\Response;

use App\ProductResearchOzon\Domain\CalendarPreview;
use App\ProductResearchOzon\Domain\MonthSlice;
use App\ProductResearchOzon\Domain\PeriodRange;

final readonly class PreviewResponse
{
    /** @var list<PeriodResponse> */
    public array $periods;
    /** @var list<MonthResponse> */
    public array $months;
    public int $monthCount;
    public string $summary;
    public string $calendarDate;
    public string $timezone;
    public ?string $suggestedName;

    public function __construct(CalendarPreview $preview, ?string $categoryPath = null, public ?int $cachedMonthCount = null)
    {
        $this->periods = array_map(static fn (PeriodRange $range): PeriodResponse => new PeriodResponse($range), $preview->periods);
        $this->months = array_map(static fn (MonthSlice $month): MonthResponse => new MonthResponse($month), $preview->months);
        $this->monthCount = \count($preview->months);
        $this->summary = $preview->summary;
        $this->calendarDate = $preview->calendarDate;
        $this->timezone = CalendarPreview::Timezone;
        $this->suggestedName = null === $categoryPath ? null : $preview->suggestedName($categoryPath);
    }
}
