<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\Identity\Application\Facade\IdentityFacade;
use App\SalesPlanning\Domain\SalesPlanAuditAction;
use App\SalesPlanning\Domain\SalesPlanMetric;
use App\SalesPlanning\Domain\SalesPlanRepository;
use App\SalesPlanning\Domain\SalesPlanStatus;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Uid\Uuid;

final readonly class SetSalesPlanMetricAction
{
    public function __construct(private SalesPlanRepository $plans, private IdentityFacade $identity)
    {
    }

    public function __invoke(string $companyId, string $planId, int $expectedVersion, SalesPlanMetric $metric, string $actorUserId): SetSalesPlanMetricResult
    {
        $plan = $this->plans->get($companyId, Uuid::fromString($planId));
        if (null === $plan) {
            return new SetSalesPlanMetricResult(SetSalesPlanMetricOutcome::NotFound);
        }
        if (SalesPlanStatus::Draft !== $plan->status()) {
            return new SetSalesPlanMetricResult(SetSalesPlanMetricOutcome::NotEditable);
        }
        if ($plan->version() !== $expectedVersion) {
            return new SetSalesPlanMetricResult(SetSalesPlanMetricOutcome::VersionConflict);
        }
        if (!$plan->canSelectMetric($metric)) {
            return new SetSalesPlanMetricResult(SetSalesPlanMetricOutcome::MetricImmutable);
        }
        $previous = $plan->metric();
        if ($previous === $metric) {
            return new SetSalesPlanMetricResult(SetSalesPlanMetricOutcome::Saved, $plan->version());
        }
        $plan->selectMetric($metric, new \DateTimeImmutable());
        $this->identity->recordAuditEntry(
            companyId: $companyId,
            actorUserId: $actorUserId,
            action: SalesPlanAuditAction::MetricSelected,
            subjectId: $planId,
            previousValue: json_encode(['metric' => $previous?->value], \JSON_THROW_ON_ERROR),
            newValue: json_encode(['metric' => $metric->value, 'baselinePlanId' => $plan->lineageId()->toRfc4122()], \JSON_THROW_ON_ERROR),
        );
        try {
            $this->plans->saveMetadata($plan);
        } catch (OptimisticLockException) {
            return new SetSalesPlanMetricResult(SetSalesPlanMetricOutcome::VersionConflict);
        }

        return new SetSalesPlanMetricResult(SetSalesPlanMetricOutcome::Saved, $plan->version());
    }
}
