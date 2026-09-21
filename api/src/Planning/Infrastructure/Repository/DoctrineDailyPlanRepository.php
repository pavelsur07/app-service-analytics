<?php

declare(strict_types=1);

namespace App\Planning\Infrastructure\Repository;

use App\Planning\Domain\DailyPlan;
use App\Planning\Domain\DailyPlanRepository;
use App\Planning\Domain\PlanChange;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineDailyPlanRepository implements DailyPlanRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function get(string $companyId, string $marketplaceAccountId, string $marketplaceSku, \DateTimeImmutable $businessDate): ?DailyPlan
    {
        $plan = $this->entityManager->createQueryBuilder()
            ->select('plan')->from(DailyPlan::class, 'plan')
            ->where('plan.companyId = :company')->andWhere('plan.marketplaceAccountId = :account')
            ->andWhere('plan.marketplaceSku = :sku')->andWhere('plan.businessDate = :date')
            ->setParameter('company', $companyId, 'uuid')->setParameter('account', $marketplaceAccountId, 'uuid')
            ->setParameter('sku', $marketplaceSku)->setParameter('date', $businessDate->setTime(0, 0), 'date_immutable')
            ->getQuery()->getOneOrNullResult();
        \assert(null === $plan || $plan instanceof DailyPlan);

        return $plan;
    }

    public function add(DailyPlan $plan, PlanChange $change): void
    {
        $this->entityManager->wrapInTransaction(function () use ($plan, $change): void {
            $this->entityManager->persist($plan);
            $this->entityManager->persist($change);
        });
    }

    public function save(PlanChange $change): void
    {
        $this->entityManager->wrapInTransaction(function () use ($change): void {
            $this->entityManager->persist($change);
        });
    }
}
