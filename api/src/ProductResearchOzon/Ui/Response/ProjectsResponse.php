<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\Response;

final readonly class ProjectsResponse
{
    /** @param list<ProjectSummaryResponse> $items */
    public function __construct(public array $items, public ?string $nextCursor)
    {
    }
}
