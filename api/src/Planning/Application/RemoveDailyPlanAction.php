<?php

declare(strict_types=1);

namespace App\Planning\Application;

use App\Planning\Domain\DailyPlanMutationOutcome;
use App\Planning\Domain\DailyPlanRepository;
use App\Planning\Domain\PlanChange;
use App\Planning\Infrastructure\Query\DailyPlansQuery;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Uid\Uuid;

final readonly class RemoveDailyPlanAction
{
    public function __construct(
        private DailyPlanRepository $plans,
        private DailyPlanAccess $access,
        private DailyPlansQuery $query,
    ) {
    }

    public function __invoke(string $companyId, string $marketplaceAccountId, string $marketplaceSku, \DateTimeImmutable $date, int $expectedVersion, string $actorId): DailyPlanMutationResult
    {
        $scope = $this->access->check($companyId, $marketplaceAccountId, $marketplaceSku);
        if (DailyPlanMutationOutcome::Saved !== $scope) {
            return new DailyPlanMutationResult($scope, null);
        }
        $date = $date->setTime(0, 0);
        $current = $this->plans->get($companyId, $marketplaceAccountId, $marketplaceSku, $date);
        if (null === $current) {
            return 0 === $expectedVersion
                ? new DailyPlanMutationResult(DailyPlanMutationOutcome::Saved, new DailyPlanItem($date->format('Y-m-d'), null, 0))
                : new DailyPlanMutationResult(DailyPlanMutationOutcome::VersionConflict, new DailyPlanItem($date->format('Y-m-d'), null, 0));
        }
        if ($current->version() !== $expectedVersion) {
            return new DailyPlanMutationResult(DailyPlanMutationOutcome::VersionConflict, DailyPlanItem::fromPlan($current));
        }
        if (null === $current->quantity()) {
            return new DailyPlanMutationResult(DailyPlanMutationOutcome::Saved, DailyPlanItem::fromPlan($current));
        }
        $oldQuantity = $current->quantity();
        $oldVersion = $current->version();
        $now = new \DateTimeImmutable();
        $actor = Uuid::fromString($actorId);
        $current->remove($actor, $now);
        try {
            $this->plans->save(PlanChange::changed($current, $oldQuantity, $oldVersion));
        } catch (OptimisticLockException) {
            /** @var array{business_date: string, quantity: int|string|null, version: int|string}|false $raw */
            $raw = $this->query->build($companyId, $marketplaceAccountId, $marketplaceSku, $date, $date)
                ->executeQuery()->fetchAssociative();
            if (false === $raw) {
                throw new \LogicException('Не удалось прочитать актуальную версию плана.');
            }
            $row = DailyPlansQuery::mapRow($raw);

            return new DailyPlanMutationResult(
                DailyPlanMutationOutcome::VersionConflict,
                new DailyPlanItem($row->businessDate, $row->quantity, $row->version),
            );
        }

        return new DailyPlanMutationResult(DailyPlanMutationOutcome::Saved, DailyPlanItem::fromPlan($current));
    }
}
