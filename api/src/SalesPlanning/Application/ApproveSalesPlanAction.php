<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\Identity\Application\Facade\IdentityFacade;
use App\SalesPlanning\Domain\EmptySalesPlan;
use App\SalesPlanning\Domain\SalesPlanApprovalConflict;
use App\SalesPlanning\Domain\SalesPlanAuditAction;
use App\SalesPlanning\Domain\SalesPlanRepository;
use App\SalesPlanning\Domain\SalesPlanStatus;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Uid\Uuid;

final readonly class ApproveSalesPlanAction
{
    public function __construct(private SalesPlanRepository $plans, private IdentityFacade $identity)
    {
    }

    public function __invoke(string $companyId, string $planId, int $expectedVersion, string $actorUserId): ApproveSalesPlanResult
    {
        $plan = $this->plans->get($companyId, Uuid::fromString($planId));
        if (null === $plan) {
            return new ApproveSalesPlanResult(ApproveSalesPlanOutcome::NotFound);
        }
        if ($expectedVersion !== $plan->version()) {
            return new ApproveSalesPlanResult(ApproveSalesPlanOutcome::VersionConflict);
        }
        if (SalesPlanStatus::Draft !== $plan->status()) {
            return new ApproveSalesPlanResult(ApproveSalesPlanOutcome::NotDraft);
        }
        if (null === $plan->metric()) {
            return new ApproveSalesPlanResult(ApproveSalesPlanOutcome::MissingMetric);
        }
        $at = new \DateTimeImmutable();
        $plan->approve(Uuid::fromString($actorUserId), $at);
        $this->identity->recordAuditEntry(
            companyId: $companyId,
            actorUserId: $actorUserId,
            action: SalesPlanAuditAction::Approved,
            subjectId: $planId,
            previousValue: json_encode(['status' => SalesPlanStatus::Draft->value], \JSON_THROW_ON_ERROR),
            newValue: json_encode(['status' => SalesPlanStatus::Approved->value, 'approvedByUserId' => $actorUserId, 'approvedAt' => $at->format(\DATE_ATOM)], \JSON_THROW_ON_ERROR),
        );
        try {
            $this->plans->approve($companyId, $plan);
        } catch (OptimisticLockException) {
            return new ApproveSalesPlanResult(ApproveSalesPlanOutcome::VersionConflict);
        } catch (EmptySalesPlan) {
            return new ApproveSalesPlanResult(ApproveSalesPlanOutcome::EmptyPlan);
        } catch (SalesPlanApprovalConflict $conflict) {
            return new ApproveSalesPlanResult(ApproveSalesPlanOutcome::DayConflict, conflicts: $conflict->conflicts);
        }

        return new ApproveSalesPlanResult(ApproveSalesPlanOutcome::Approved, $planId, $plan->version());
    }
}
