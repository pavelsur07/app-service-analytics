<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Application;

use App\ProductResearchOzon\Domain\ResearchProblem;
use App\ProductResearchOzon\Domain\ValueObject\RunView;
use App\ProductResearchOzon\Infrastructure\Query\ResearchRunsQuery;

final readonly class GetResearchRunAction
{
    public function __construct(private ResearchRunsQuery $query)
    {
    }

    public function __invoke(string $companyId, string $projectId, string $runId): RunView
    {
        return ResearchRunsQuery::map($this->query->detail($companyId, $projectId, $runId)->executeQuery()->fetchAllAssociative()) ?? throw new ResearchProblem('run_not_found');
    }
}
