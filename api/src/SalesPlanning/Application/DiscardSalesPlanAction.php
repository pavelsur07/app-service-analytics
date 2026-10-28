<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\Identity\Application\Facade\IdentityFacade;
use App\SalesPlanning\Domain\SalesPlanAuditAction;
use App\SalesPlanning\Domain\SalesPlanRepository;
use App\SalesPlanning\Domain\SalesPlanStatus;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Uid\Uuid;

final readonly class DiscardSalesPlanAction
{
    public function __construct(private SalesPlanRepository $plans, private IdentityFacade $identity)
    {
    }

    public function __invoke(string $companyId, string $planId, int $expectedVersion, string $actorUserId): CloseSalesPlanResult
    {
        $plan = $this->plans->get($companyId, Uuid::fromString($planId));
        if (null === $plan) {
            return new CloseSalesPlanResult(CloseSalesPlanOutcome::NotFound);
        }
        if ($plan->version() !== $expectedVersion) {
            return new CloseSalesPlanResult(CloseSalesPlanOutcome::VersionConflict);
        }
        if (SalesPlanStatus::Draft !== $plan->status()) {
            return new CloseSalesPlanResult(CloseSalesPlanOutcome::InvalidStatus);
        }
        try {
            return $this->plans->transactional(function () use ($companyId, $planId, $actorUserId, $plan): CloseSalesPlanResult {
                $plan->discard(new \DateTimeImmutable());
                $this->identity->recordAuditEntry($companyId, $actorUserId, SalesPlanAuditAction::Discarded, $planId,
                    json_encode(['status' => SalesPlanStatus::Draft->value], \JSON_THROW_ON_ERROR),
                    json_encode(['status' => SalesPlanStatus::Discarded->value], \JSON_THROW_ON_ERROR));
                $this->plans->saveMetadata($plan);

                return new CloseSalesPlanResult(CloseSalesPlanOutcome::Changed, $planId, $plan->version(), $plan->status());
            });
        } catch (OptimisticLockException) {
            return new CloseSalesPlanResult(CloseSalesPlanOutcome::VersionConflict);
        }
    }
}
