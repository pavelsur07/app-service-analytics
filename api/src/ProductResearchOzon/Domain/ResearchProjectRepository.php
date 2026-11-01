<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain;

interface ResearchProjectRepository
{
    /** @template T
     * @param callable(): T $operation
     *
     * @return T
     */
    public function transactional(callable $operation): mixed;

    public function locked(string $companyId, string $projectId, int $expectedVersion, bool $forCopy = false): ResearchProject;

    /** @return list<PeriodRange> */
    public function periods(string $companyId, string $projectId): array;

    /** @param list<PeriodRange> $periods */
    public function add(ResearchProject $project, array $periods): void;

    /** @param list<PeriodRange> $newPeriods */
    public function append(ResearchProject $project, array $newPeriods, int $offset): void;
}
