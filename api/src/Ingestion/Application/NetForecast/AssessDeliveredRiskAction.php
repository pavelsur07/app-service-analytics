<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\DeliveredRiskQuantities;
use App\Ingestion\Domain\ValueObject\ObservedReturnCurve;
use App\Ingestion\Domain\ValueObject\ReturnProbability;
use App\Ingestion\Domain\ValueObject\ReturnProbabilityAssessment;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;

/** Applies current delivered risk; no source reads, historical manifest or rollout decision. */
final readonly class AssessDeliveredRiskAction
{
    private const int LAG_DAYS = 7;

    public function __construct(private BuildReturnCurveAction $curves)
    {
    }

    public function assess(string $companyId, string $accountId, DeliveredTargetEvidence $target, NetTrainingBatch $batch): DeliveredRiskAssessment
    {
        $target->context->assertScope($companyId, $accountId);
        $target->readiness?->context->assertScope($companyId, $accountId);
        if ($batch->companyId !== $companyId || $batch->accountId !== $accountId) {
            throw new \InvalidArgumentException('Training batch belongs to another company or account.');
        }
        $batch->context?->assertScope($companyId, $accountId);
        if (null === $target->context->snapshotId || '' === trim($target->context->snapshotId) || 'single_statement' === $target->context->snapshotId) {
            return DeliveredRiskAssessment::unavailable('target_snapshot_missing');
        }
        if ($batch->readAt->format('U.u') !== $target->context->readAt->format('U.u')
            || (null !== $batch->context && $batch->context->fingerprint() !== $target->context->fingerprint())) {
            return DeliveredRiskAssessment::unavailable('training_context_mismatch');
        }
        if (null !== $target->unavailableReason || !$target->inputComplete) {
            return DeliveredRiskAssessment::unavailable($target->unavailableReason ?? 'target_input_incomplete');
        }
        if (null === $target->currentD) {
            return DeliveredRiskAssessment::unavailable('unknown_outcome');
        }
        $origin = $target->deliveryOrigin;
        $readAt = $target->context->readAt;
        if (null !== $origin && $origin > $readAt) {
            return DeliveredRiskAssessment::unavailable('delivery_observation_in_future');
        }
        if (null !== $origin && !$target->context->epoch->contains($origin)) {
            return DeliveredRiskAssessment::unavailable('source_history_unavailable');
        }
        if (0 === $target->currentD) {
            return DeliveredRiskAssessment::available(DeliveredRiskQuantities::withoutFutureReturns(0), null, null, 'no_remaining_delivery', []);
        }
        if (null === $origin) {
            return DeliveredRiskAssessment::unavailable('delivery_observation_unknown');
        }
        $horizonAt = $origin->add(new \DateInterval(\sprintf('P%dD', ObservedReturnCurve::HORIZON)));
        if ($readAt >= $horizonAt) {
            return $this->mature($target, $origin);
        }
        // In this branch elapsed < H, so subtraction stays bounded; preserve subsecond boundaries.
        $seconds = $readAt->getTimestamp() - $origin->getTimestamp();
        if ($readAt->format('u') < $origin->format('u')) {
            --$seconds;
        }
        $age = intdiv($seconds, 86400);
        $attempts = [];
        foreach ([$target->marketplaceSku, null] as $sku) {
            $source = $this->curves->build($companyId, $accountId, $batch, $sku);
            $assessment = null === $source->curve
                ? ReturnProbabilityAssessment::unavailable($source->unavailableReason ?? 'return_training_profile_missing')
                : $source->curve->remainingAt($age);
            $attempts[] = new DeliveredRiskAttempt($source, $assessment);
            if (null !== $assessment->probability) {
                return DeliveredRiskAssessment::available(DeliveredRiskQuantities::fromProbability($target->currentD, $assessment->probability), $assessment->probability, $age, null === $sku ? 'account' : 'sku', $attempts);
            }
        }

        return DeliveredRiskAssessment::unavailable('return_risk_unavailable', $age, $attempts);
    }

    private function mature(DeliveredTargetEvidence $target, \DateTimeImmutable $origin): DeliveredRiskAssessment
    {
        $readyAt = $origin->add(new \DateInterval(\sprintf('P%dD', ObservedReturnCurve::HORIZON + self::LAG_DAYS)));
        $readAt = $target->context->readAt;
        $reason = null;
        $proof = $target->readiness;
        if ($readAt < $readyAt) {
            $reason = 'target_horizon_not_ready';
        } elseif (null === $proof) {
            $reason = 'target_readiness_missing';
        } elseif ($proof->context->fingerprint() !== $target->context->fingerprint()
            || $proof->orderNumber !== $target->orderNumber || $proof->marketplaceSku !== $target->marketplaceSku
            || $proof->deliveryOrigin->format('U.u') !== $origin->format('U.u')) {
            $reason = 'target_readiness_mismatch';
        } elseif (NetTrainingContext::COVERAGE_POLICY !== $proof->coveragePolicy) {
            $reason = 'unsupported_target_coverage_policy';
        } elseif ($proof->coverageFrom > $readAt || $proof->coverageThrough > $readAt
            || (null !== $proof->deepCompletedAt && $proof->deepCompletedAt > $readAt)) {
            $reason = 'target_evidence_in_future';
        } elseif (!$proof->historyComplete) {
            $reason = 'source_history_unavailable';
        } elseif (!$proof->postingsCoverageComplete) {
            $reason = 'postings_coverage_missing';
        } elseif (!$proof->returnsCoverageComplete || $proof->coverageFrom > $origin || $proof->coverageThrough < $readyAt
            || null === $proof->deepCompletedAt || $proof->deepCompletedAt < $readyAt) {
            $reason = 'returns_coverage_missing';
        }
        if (null !== $reason) {
            return DeliveredRiskAssessment::unavailable($reason, ObservedReturnCurve::HORIZON);
        }
        // The caller has already established a positive, reconciled current D.
        \assert(null !== $target->currentD);

        return DeliveredRiskAssessment::available(DeliveredRiskQuantities::withoutFutureReturns($target->currentD), new ReturnProbability(0, 1), ObservedReturnCurve::HORIZON, 'mature_target', []);
    }
}
