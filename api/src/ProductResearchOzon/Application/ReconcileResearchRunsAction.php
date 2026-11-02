<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Application;

use App\ProductResearchOzon\Domain\ValueObject\RunProgress;
use App\ProductResearchOzon\Infrastructure\Query\ResearchRunsQuery;

final readonly class ReconcileResearchRunsAction
{
    public function __construct(private ResearchRunsQuery $query, private SynchronizeResearchRunAction $sync)
    {
    }

    public function __invoke(string $companyId, ?string $after, int $limit): ReconciliationResult
    {
        if (null !== $after && !RunProgress::validId($after)) {
            throw new \InvalidArgumentException('invalid_cursor');
        }
        $rows = $this->query->reconcile($companyId, $after, $limit)->executeQuery()->fetchAllAssociative();
        $last = null;
        $failed = 0;
        foreach ($rows as $row) {
            $last = ResearchRunsQuery::text($row, 'id');
            if (!($this->sync)($companyId, $last, true)) {
                ++$failed;
            }
        }

        return new ReconciliationResult(\count($rows) === $limit ? $last : null, \count($rows), $failed);
    }
}
