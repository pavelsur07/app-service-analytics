<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Application;

use App\ProductResearchOzon\Domain\PeriodRange;

final readonly class AddPeriodsInput
{
    /** @param list<PeriodRange> $periods */
    public function __construct(public array $periods, public int $expectedVersion, public string $previewCalendarDate, public ?bool $includeCurrentMonth = null)
    {
    }
}
