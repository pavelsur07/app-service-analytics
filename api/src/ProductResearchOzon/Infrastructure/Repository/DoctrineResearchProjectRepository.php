<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Infrastructure\Repository;

use App\ProductResearchOzon\Domain\PeriodRange;
use App\ProductResearchOzon\Domain\ResearchPeriod;
use App\ProductResearchOzon\Domain\ResearchProblem;
use App\ProductResearchOzon\Domain\ResearchProject;
use App\ProductResearchOzon\Domain\ResearchProjectRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\Query;

final readonly class DoctrineResearchProjectRepository implements ResearchProjectRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function transactional(callable $operation): mixed
    {
        try {
            return $this->em->wrapInTransaction($operation);
        } catch (UniqueConstraintViolationException|OptimisticLockException) {
            throw new ResearchProblem('version_conflict');
        }
    }

    public function locked(string $companyId, string $projectId, int $expectedVersion, bool $forCopy = false): ResearchProject
    {
        $project = $this->em->createQuery('SELECT p FROM '.ResearchProject::class.' p WHERE p.companyId = :company AND p.id = :id')
            ->setParameter('company', $companyId)->setParameter('id', $projectId)->setMaxResults(1)
            ->setLockMode($forCopy ? LockMode::PESSIMISTIC_READ : LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
        if (!$project instanceof ResearchProject) {
            throw new ResearchProblem('project_not_found');
        }
        if ($project->version() !== $expectedVersion) {
            throw new ResearchProblem('version_conflict');
        }

        return $project;
    }

    public function periods(string $companyId, string $projectId): array
    {
        /** @var list<ResearchPeriod> $rows */
        $rows = $this->em->createQuery('SELECT p FROM '.ResearchPeriod::class." p WHERE IDENTITY(p.project, 'companyId') = :company AND IDENTITY(p.project, 'id') = :project ORDER BY p.position")
            ->setParameter('company', $companyId)->setParameter('project', $projectId)->setMaxResults(24)->getResult();

        return array_map(static fn (ResearchPeriod $row): PeriodRange => $row->range(), $rows);
    }

    public function add(ResearchProject $project, array $periods): void
    {
        $this->em->persist($project);
        $this->append($project, $periods, 0);
    }

    public function append(ResearchProject $project, array $newPeriods, int $offset): void
    {
        foreach ($newPeriods as $position => $range) {
            $this->em->persist(new ResearchPeriod($project, $range, $offset + $position));
        }
    }
}
