<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Infrastructure\Query;

use App\ProductResearchOzon\Domain\PeriodRange;

final readonly class ResearchProjectView
{
    /** @param list<PeriodRange> $periods */
    public function __construct(public ResearchProjectRow $project, public array $periods)
    {
    }
}
