<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\Identity\Application\Facade\IdentityFacade;
use App\SalesPlanning\Domain\SalesPlanAuditAction;
use App\SalesPlanning\Domain\SalesPlanRepository;
use App\SalesPlanning\Domain\SalesPlanStatus;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Uid\Uuid;

final readonly class CreateSalesPlanRevisionAction
{
    public function __construct(private SalesPlanRepository $plans, private IdentityFacade $identity)
    {
    }

    public function __invoke(string $companyId, string $sourceId, int $expectedVersion, string $actorUserId): CreateSalesPlanRevisionResult
    {
        $source = $this->plans->get($companyId, Uuid::fromString($sourceId));
        if (null === $source) {
            return new CreateSalesPlanRevisionResult(CreateSalesPlanRevisionOutcome::NotFound);
        }
        if ($source->version() !== $expectedVersion) {
            return new CreateSalesPlanRevisionResult(CreateSalesPlanRevisionOutcome::VersionConflict);
        }
        if (SalesPlanStatus::Approved !== $source->status()) {
            return new CreateSalesPlanRevisionResult(CreateSalesPlanRevisionOutcome::NotApproved);
        }
        try {
            return $this->plans->transactional(function () use ($companyId, $source, $actorUserId): CreateSalesPlanRevisionResult {
                $at = new \DateTimeImmutable();
                $source->recordLifecycleChanged($at);
                $this->plans->saveMetadata($source);
                $existing = $this->plans->openDraft($companyId, $source->lineageId());
                if (null !== $existing) {
                    return new CreateSalesPlanRevisionResult(CreateSalesPlanRevisionOutcome::Created, $existing);
                }
                $draft = $source->newRevision(Uuid::fromString($actorUserId), $at, $this->plans->nextVersionNumber($companyId, $source->lineageId()));
                $this->identity->recordAuditEntry($companyId, $actorUserId, SalesPlanAuditAction::RevisionCreated, $draft->id()->toRfc4122(), null,
                    json_encode(['sourcePlanId' => $source->id()->toRfc4122(), 'versionNumber' => $draft->versionNumber(), 'status' => $draft->status()->value], \JSON_THROW_ON_ERROR));
                $this->plans->copyRevision($companyId, $source, $draft);

                return new CreateSalesPlanRevisionResult(CreateSalesPlanRevisionOutcome::Created, $draft);
            });
        } catch (OptimisticLockException) {
            return new CreateSalesPlanRevisionResult(CreateSalesPlanRevisionOutcome::VersionConflict);
        }
    }
}
