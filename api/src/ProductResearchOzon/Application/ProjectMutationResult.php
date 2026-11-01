<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Application;

use App\ProductResearchOzon\Domain\CalendarPreview;
use App\ProductResearchOzon\Domain\ResearchProject;

final readonly class ProjectMutationResult
{
    public function __construct(public ResearchProject $project, public CalendarPreview $preview)
    {
    }
}
