<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Infrastructure\Query;

use App\ProductResearchOzon\Domain\MonthSlice;
use App\ProductResearchOzon\Domain\ValueObject\RunMonthState;

final readonly class ProjectMonthRow
{
    public function __construct(public MonthSlice $period, public ?RunMonthState $attempt)
    {
    }
}
