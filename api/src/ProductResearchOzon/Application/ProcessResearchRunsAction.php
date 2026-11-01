<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Application;

use App\ProductResearchOzon\Infrastructure\Query\ResearchRunsQuery;
use Psr\Clock\ClockInterface;

final readonly class ProcessResearchRunsAction
{
    public function __construct(private ResearchRunsQuery $query, private RelayResearchRunAction $relay, private SynchronizeResearchRunAction $sync, private ClockInterface $clock)
    {
    }

    public function __invoke(string $companyId): void
    {
        foreach ([true, false] as $delivery) {
            $rows = $this->query->due($companyId, $delivery, $this->clock->now())->executeQuery()->fetchAllAssociative();
            foreach ($rows as $row) {
                $runId = ResearchRunsQuery::text($row, 'id');
                if ($delivery) {
                    ($this->relay)($companyId, $runId);
                } else {
                    ($this->sync)($companyId, $runId);
                }
            }
        }
    }
}
