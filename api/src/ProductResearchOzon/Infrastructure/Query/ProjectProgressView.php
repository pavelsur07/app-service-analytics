<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Infrastructure\Query;

final readonly class ProjectProgressView
{
    /** @param list<ProjectMonthRow> $months */
    public function __construct(public string $projectId, public int $version, public string $status, public ?string $lastRunId, public ?int $runRevision, public array $months)
    {
    }
}
