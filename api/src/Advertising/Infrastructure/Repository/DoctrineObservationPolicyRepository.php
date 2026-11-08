<?php

declare(strict_types=1);

namespace App\Advertising\Infrastructure\Repository;

use App\Advertising\Domain\ObservationEvent;
use App\Advertising\Domain\ObservationPolicy;
use App\Advertising\Domain\ObservationPolicyRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\Query;

final readonly class DoctrineObservationPolicyRepository implements ObservationPolicyRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function transactional(callable $operation): mixed
    {
        try {
            return $this->em->wrapInTransaction($operation);
        } catch (UniqueConstraintViolationException|OptimisticLockException) {
            throw new \DomainException('version_conflict');
        }
    }

    public function add(ObservationPolicy $policy): void
    {
        $db = $this->em->getConnection();
        $company = $policy->companyId()->toRfc4122();
        $account = $policy->accountId()->toRfc4122();
        $db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:scope,52))', ['scope' => $company.':'.$account]);
        $ids = $db->executeQuery('SELECT id FROM advertising_observation_policy WHERE company_id=:company AND account_id=:account LIMIT 10', ['company' => $company, 'account' => $account])->fetchFirstColumn();
        if (10 === \count($ids)) {
            throw new \DomainException('account_capacity_exceeded');
        }
        $this->em->persist($policy);
    }

    public function recordEvent(ObservationEvent $event): void
    {
        $this->em->persist($event);
    }

    public function locked(string $companyId, string $policyId, int $version): ObservationPolicy
    {
        $policy = $this->em->createQuery('SELECT p FROM '.ObservationPolicy::class.' p WHERE p.companyId = :company AND p.id = :id')
            ->setParameter('company', $companyId)->setParameter('id', $policyId)->setMaxResults(1)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
        if (!$policy instanceof ObservationPolicy) {
            throw new \DomainException('policy_not_found');
        }
        if ($policy->version() !== $version) {
            throw new \DomainException('version_conflict');
        }

        return $policy;
    }
}
