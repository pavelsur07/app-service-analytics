<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\Identity\Application\Facade\IdentityFacade;
use App\SalesPlanning\Domain\SalesPlanAuditAction;
use App\SalesPlanning\Domain\SalesPlanRepository;
use App\SalesPlanning\Domain\SalesPlanStatus;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Uid\Uuid;

final readonly class CancelSalesPlanAction
{
    public function __construct(private SalesPlanRepository $plans, private IdentityFacade $identity)
    {
    }

    public function __invoke(string $companyId, string $planId, int $expectedVersion, string $actorUserId): CloseSalesPlanResult
    {
        $source = $this->plans->get($companyId, Uuid::fromString($planId));
        if (null === $source) {
            return new CloseSalesPlanResult(CloseSalesPlanOutcome::NotFound);
        }
        if ($source->version() !== $expectedVersion) {
            return new CloseSalesPlanResult(CloseSalesPlanOutcome::VersionConflict);
        }
        if (SalesPlanStatus::Approved !== $source->status()) {
            return new CloseSalesPlanResult(CloseSalesPlanOutcome::InvalidStatus);
        }
        try {
            return $this->plans->transactional(function () use ($companyId, $planId, $actorUserId, $source): CloseSalesPlanResult {
                $at = new \DateTimeImmutable();
                $source->cancel($at);
                $this->identity->recordAuditEntry($companyId, $actorUserId, SalesPlanAuditAction::Cancelled, $planId,
                    json_encode(['status' => SalesPlanStatus::Approved->value], \JSON_THROW_ON_ERROR),
                    json_encode(['status' => SalesPlanStatus::Cancelled->value], \JSON_THROW_ON_ERROR));
                // Общий lock order с copy/replacement: approved source первым.
                $this->plans->saveMetadata($source);
                $draft = $this->plans->openDraft($companyId, $source->lineageId());
                if (null !== $draft) {
                    $draft->discard($at);
                    $this->identity->recordAuditEntry($companyId, $actorUserId, SalesPlanAuditAction::Discarded, $draft->id()->toRfc4122(),
                        json_encode(['status' => SalesPlanStatus::Draft->value], \JSON_THROW_ON_ERROR),
                        json_encode(['status' => SalesPlanStatus::Discarded->value, 'cancelledPlanId' => $planId], \JSON_THROW_ON_ERROR));
                    $this->plans->saveMetadata($draft);
                }
                $this->plans->releaseClaims($companyId, $source);

                return new CloseSalesPlanResult(CloseSalesPlanOutcome::Changed, $planId, $source->version(), $source->status());
            });
        } catch (OptimisticLockException) {
            return new CloseSalesPlanResult(CloseSalesPlanOutcome::VersionConflict);
        }
    }
}
