<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\Identity\Application\Facade\IdentityFacade;
use App\SalesPlanning\Domain\SalesPlanAuditAction;
use App\SalesPlanning\Domain\SalesPlanCalendarRepository;
use App\SalesPlanning\Domain\SalesPlanRepository;
use App\SalesPlanning\Domain\SalesPlanStatus;
use App\SalesPlanning\Domain\SkuPeriodPlan;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Uid\Uuid;

/**
 * SKU убирается из черновика целиком (ADR-040 п. 8). Строки удаляются,
 * но прежние значения всех дней остаются в журнале (ADR-011): «что
 * стояло до правки» восстанавливается из записи.
 */
final readonly class RemoveSalesPlanSkuAction
{
    public function __construct(
        private SalesPlanRepository $plans,
        private IdentityFacade $identity,
        private SalesPlanCalendarRepository $calendar,
    ) {
    }

    public function __invoke(
        string $companyId,
        string $planId,
        int $expectedVersion,
        string $marketplaceSku,
        string $actorUserId,
    ): EditSalesPlanResult {
        $plan = $this->plans->get($companyId, Uuid::fromString($planId));
        if (null === $plan) {
            return EditSalesPlanResult::of(EditSalesPlanOutcome::NotFound);
        }

        if (SalesPlanStatus::Draft !== $plan->status()) {
            return EditSalesPlanResult::of(EditSalesPlanOutcome::NotEditable);
        }

        if ($plan->version() !== $expectedVersion) {
            return EditSalesPlanResult::of(EditSalesPlanOutcome::VersionConflict);
        }

        $days = $this->plans->daysOfSkus($companyId, $plan->id(), [$marketplaceSku])[$marketplaceSku] ?? null;
        if (null === $days) {
            return EditSalesPlanResult::of(EditSalesPlanOutcome::SkuNotInPlan);
        }

        foreach ($this->calendar->entriesForUpdate($companyId, $plan->id(), $marketplaceSku) as $entry) {
            $this->identity->recordAuditEntry(
                companyId: $companyId,
                actorUserId: $actorUserId,
                action: SalesPlanAuditAction::CalendarRemoved,
                subjectId: $entry->id()->toRfc4122(),
                previousValue: SetSalesPlanCalendarAction::describe($planId, $entry),
                newValue: null,
            );
            $this->calendar->remove($entry);
        }

        $this->identity->recordAuditEntry(
            companyId: $companyId,
            actorUserId: $actorUserId,
            action: SalesPlanAuditAction::SkuRemoved,
            subjectId: $plan->id()->toRfc4122(),
            previousValue: SkuPeriodPlan::stored($marketplaceSku, $days)->describe($plan),
            newValue: null,
        );

        $plan->recordLinesChanged(new \DateTimeImmutable());

        try {
            $this->plans->removeSku($plan, $marketplaceSku);
        } catch (OptimisticLockException) {
            return EditSalesPlanResult::of(EditSalesPlanOutcome::VersionConflict);
        }

        return EditSalesPlanResult::saved($plan->version());
    }
}
