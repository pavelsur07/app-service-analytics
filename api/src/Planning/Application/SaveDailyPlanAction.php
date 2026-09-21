<?php

declare(strict_types=1);

namespace App\Planning\Application;

use App\Planning\Domain\DailyPlan;
use App\Planning\Domain\DailyPlanMutationOutcome;
use App\Planning\Domain\DailyPlanRepository;
use App\Planning\Domain\PlanChange;
use App\Planning\Infrastructure\Query\DailyPlansQuery;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Uid\Uuid;

final readonly class SaveDailyPlanAction
{
    public function __construct(
        private DailyPlanRepository $plans,
        private DailyPlanAccess $access,
        private DailyPlansQuery $query,
    ) {
    }

    public function __invoke(string $companyId, string $marketplaceAccountId, string $marketplaceSku, \DateTimeImmutable $date, int $quantity, int $expectedVersion, string $actorId): DailyPlanMutationResult
    {
        $scope = $this->access->check($companyId, $marketplaceAccountId, $marketplaceSku);
        if (DailyPlanMutationOutcome::Saved !== $scope) {
            return new DailyPlanMutationResult($scope, null);
        }
        $date = $date->setTime(0, 0);
        $current = $this->plans->get($companyId, $marketplaceAccountId, $marketplaceSku, $date);
        if (null === $current) {
            if (0 !== $expectedVersion) {
                return new DailyPlanMutationResult(DailyPlanMutationOutcome::VersionConflict, new DailyPlanItem($date->format('Y-m-d'), null, 0));
            }
            $now = new \DateTimeImmutable();
            $plan = DailyPlan::create(Uuid::fromString($companyId), Uuid::fromString($marketplaceAccountId), $marketplaceSku, $date, $quantity, Uuid::fromString($actorId), $now);
            try {
                $this->plans->add($plan, PlanChange::created($plan));
            } catch (UniqueConstraintViolationException|OptimisticLockException) {
                return $this->conflict($companyId, $marketplaceAccountId, $marketplaceSku, $date);
            }

            return new DailyPlanMutationResult(DailyPlanMutationOutcome::Saved, DailyPlanItem::fromPlan($plan));
        }
        if ($current->version() !== $expectedVersion) {
            return new DailyPlanMutationResult(DailyPlanMutationOutcome::VersionConflict, DailyPlanItem::fromPlan($current));
        }
        if ($current->quantity() === $quantity) {
            return new DailyPlanMutationResult(DailyPlanMutationOutcome::Saved, DailyPlanItem::fromPlan($current));
        }
        $oldQuantity = $current->quantity();
        $oldVersion = $current->version();
        $now = new \DateTimeImmutable();
        $actor = Uuid::fromString($actorId);
        $current->changeQuantity($quantity, $actor, $now);
        try {
            $this->plans->save(PlanChange::changed($current, $oldQuantity, $oldVersion));
        } catch (OptimisticLockException) {
            return $this->conflict($companyId, $marketplaceAccountId, $marketplaceSku, $date);
        }

        return new DailyPlanMutationResult(DailyPlanMutationOutcome::Saved, DailyPlanItem::fromPlan($current));
    }

    private function conflict(string $companyId, string $marketplaceAccountId, string $marketplaceSku, \DateTimeImmutable $date): DailyPlanMutationResult
    {
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
}
