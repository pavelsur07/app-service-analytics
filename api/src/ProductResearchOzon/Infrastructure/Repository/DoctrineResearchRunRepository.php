<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Infrastructure\Repository;

use App\ProductResearchOzon\Domain\ResearchRun;
use App\ProductResearchOzon\Domain\ResearchRunMonth;
use App\ProductResearchOzon\Domain\ResearchRunRepository;
use App\ProductResearchOzon\Infrastructure\Query\ResearchRunsQuery;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineResearchRunRepository implements ResearchRunRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function byRequest(string $companyId, string $projectId, string $requestId): ?array
    {
        $row = $this->em->getConnection()->fetchAssociative('SELECT id, input_hash FROM product_research_ozon_run WHERE company_id = ? AND project_id = ? AND request_id = ? LIMIT 1', [$companyId, $projectId, $requestId]);

        return false === $row ? null : ['id' => ResearchRunsQuery::text($row, 'id'), 'inputHash' => ResearchRunsQuery::text($row, 'input_hash')];
    }

    public function add(ResearchRun $run, array $months): void
    {
        $this->em->persist($run);
        foreach ($months as $month) {
            $this->em->persist(new ResearchRunMonth($run, $month));
        }
    }
}
