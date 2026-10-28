<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\GrossDeliveryQuantity;

/** Current stage-only gross application. No source reads, return tail or rollout. */
final readonly class AssessPreHandoverGrossAction
{
    public function __construct(private BuildPreHandoverGrossRateAction $rates)
    {
    }

    public function assess(string $companyId, string $accountId, OpenTargetEvidence $target, NetTrainingBatch $batch): PreHandoverGrossAssessment
    {
        $target->context->assertScope($companyId, $accountId);
        if ($batch->companyId !== $companyId || $batch->accountId !== $accountId) {
            throw new \InvalidArgumentException('Training batch belongs to another company or account.');
        }
        $batch->context?->assertScope($companyId, $accountId);
        if (null === $target->context->snapshotId || '' === trim($target->context->snapshotId) || 'single_statement' === $target->context->snapshotId) {
            return PreHandoverGrossAssessment::unavailable('target_snapshot_missing');
        }
        if ($batch->readAt->format('U.u') !== $target->context->readAt->format('U.u')
            || (null !== $batch->context && $batch->context->fingerprint() !== $target->context->fingerprint())) {
            return PreHandoverGrossAssessment::unavailable('training_context_mismatch');
        }
        if (null !== $target->unavailableReason || !$target->inputComplete) {
            return PreHandoverGrossAssessment::unavailable($target->unavailableReason ?? 'target_input_incomplete');
        }
        if (OpenTargetEvidence::BEFORE_HANDOVER !== $target->stage) {
            return PreHandoverGrossAssessment::unavailable('unsupported_target_stage');
        }
        if (null === $target->eligibleOpenQuantity) {
            return PreHandoverGrossAssessment::unavailable('unknown_eligible_open_quantity');
        }
        if (0 === $target->eligibleOpenQuantity) {
            return PreHandoverGrossAssessment::available(GrossDeliveryQuantity::zero(), null, 'no_eligible_open', []);
        }
        if (null === $batch->context) {
            return PreHandoverGrossAssessment::unavailable('training_context_missing');
        }
        $attempts = [];
        foreach ([$target->marketplaceSku, null] as $sku) {
            $rate = $this->rates->build($companyId, $accountId, $batch, $sku);
            $attempts[] = $rate;
            if (null !== $rate->probability) {
                return PreHandoverGrossAssessment::available(GrossDeliveryQuantity::fromProbability($target->eligibleOpenQuantity, $rate->probability), $rate->probability, null === $sku ? 'account' : 'sku', $attempts);
            }
        }

        return PreHandoverGrossAssessment::unavailable('gross_probability_unavailable', $attempts);
    }
}
