<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain;

interface ResearchRunRepository
{
    /** @return array{id: string, inputHash: string}|null */
    public function byRequest(string $companyId, string $projectId, string $requestId): ?array;

    /** @param list<MonthSlice> $months */
    public function add(ResearchRun $run, array $months): void;
}
