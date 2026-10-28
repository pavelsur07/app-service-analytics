<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\ObservedReturnCurve;
use App\Ingestion\Domain\ValueObject\OpenRetainedQuantities;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;

/** Applies one selected current-RR pair, without reads or fallback. */
final readonly class ApplyOpenRetentionAction
{
    public function __construct(private BuildPreHandoverGrossRateAction $preRates, private BuildPostHandoverGrossRateAction $postRates, private BuildReturnCurveAction $curves)
    {
    }

    public function apply(string $companyId, string $accountId, OpenTargetEvidence $target, ScopedPreHandoverGrossRate|ScopedPostHandoverGrossRate|null $grossRate, ?ScopedReturnCurve $returnCurve): OpenRetentionAssessment
    {
        $target->context->assertScope($companyId, $accountId);
        foreach ([$grossRate, $returnCurve] as $input) {
            if (null === $input) {
                continue;
            }
            if ($input->companyId !== $companyId || $input->accountId !== $accountId) {
                throw new \InvalidArgumentException('Open retention input belongs to another company or account.');
            }
            $input->context?->assertScope($companyId, $accountId);
        }
        if (null === $target->context->snapshotId || '' === trim($target->context->snapshotId) || 'single_statement' === $target->context->snapshotId) {
            return OpenRetentionAssessment::unavailable('target_snapshot_missing', $grossRate, $returnCurve);
        }
        foreach ([$grossRate, $returnCurve] as $input) {
            if (null !== $input && ($input->readAt->format('U.u') !== $target->context->readAt->format('U.u')
                || (null !== $input->context && !$this->sameContext($input->context, $target->context)))) {
                return OpenRetentionAssessment::unavailable('training_context_mismatch', $grossRate, $returnCurve);
            }
        }
        if (null !== $target->unavailableReason || !$target->inputComplete) {
            return OpenRetentionAssessment::unavailable($target->unavailableReason ?? 'target_input_incomplete', $grossRate, $returnCurve);
        }
        if (!\in_array($target->stage, [OpenTargetEvidence::BEFORE_HANDOVER, OpenTargetEvidence::AFTER_HANDOVER], true)) {
            return OpenRetentionAssessment::unavailable('unsupported_target_stage', $grossRate, $returnCurve);
        }
        if (null === $target->eligibleOpenQuantity) {
            return OpenRetentionAssessment::unavailable('unknown_eligible_open_quantity', $grossRate, $returnCurve);
        }
        if (0 === $target->eligibleOpenQuantity) {
            return OpenRetentionAssessment::available(OpenRetainedQuantities::zero(), null, null, 'no_eligible_open', $grossRate, $returnCurve);
        }
        if (null === $grossRate) {
            return OpenRetentionAssessment::unavailable('gross_probability_unavailable', null, $returnCurve);
        }
        if ((OpenTargetEvidence::BEFORE_HANDOVER === $target->stage) !== ($grossRate instanceof ScopedPreHandoverGrossRate)) {
            return OpenRetentionAssessment::unavailable('gross_stage_mismatch', $grossRate, $returnCurve);
        }
        if ((null !== $grossRate->marketplaceSku && $grossRate->marketplaceSku !== $target->marketplaceSku)
            || (null !== $grossRate->source && $grossRate->source->marketplaceSku !== $grossRate->marketplaceSku)
            || (null !== $returnCurve && ($returnCurve->marketplaceSku !== $grossRate->marketplaceSku
                || (null !== $returnCurve->source && $returnCurve->source->marketplaceSku !== $returnCurve->marketplaceSku)))) {
            return OpenRetentionAssessment::unavailable('selected_scope_mismatch', $grossRate, $returnCurve);
        }
        if (null === $grossRate->context) {
            return OpenRetentionAssessment::unavailable('training_context_missing', $grossRate, $returnCurve);
        }
        if (null !== $grossRate->unavailableReason) {
            return OpenRetentionAssessment::unavailable($grossRate->unavailableReason, $grossRate, $returnCurve);
        }
        if (null === $grossRate->source) {
            return OpenRetentionAssessment::unavailable('gross_training_row_missing', $grossRate, $returnCurve);
        }
        if (null === $grossRate->probability) {
            return OpenRetentionAssessment::unavailable('gross_probability_unavailable', $grossRate, $returnCurve);
        }
        $batch = new NetTrainingBatch($companyId, $accountId, $grossRate->readAt, $grossRate->context, [$grossRate->source], []);
        $rebuilt = $grossRate instanceof ScopedPreHandoverGrossRate
            ? $this->preRates->build($companyId, $accountId, $batch, $grossRate->marketplaceSku)
            : $this->postRates->build($companyId, $accountId, $batch, $grossRate->marketplaceSku);
        if (null === $rebuilt->probability) {
            return OpenRetentionAssessment::unavailable($rebuilt->unavailableReason ?? 'gross_probability_unavailable', $grossRate, $returnCurve);
        }
        if ($rebuilt->probability->numerator !== $grossRate->probability->numerator
            || $rebuilt->probability->denominator !== $grossRate->probability->denominator) {
            return OpenRetentionAssessment::unavailable('invalid_gross_probability', $grossRate, $returnCurve);
        }
        $probability = $rebuilt->probability;
        $basis = null === $grossRate->marketplaceSku ? 'account' : 'sku';
        if (0 === $probability->numerator) {
            return OpenRetentionAssessment::available(OpenRetainedQuantities::fromProbabilities($target->eligibleOpenQuantity, $probability, null), $probability, null, $basis, $grossRate, $returnCurve);
        }
        if (null === $returnCurve) {
            return OpenRetentionAssessment::unavailable('return_curve_unavailable', $grossRate);
        }
        if (null === $returnCurve->context) {
            return OpenRetentionAssessment::unavailable('training_context_missing', $grossRate, $returnCurve);
        }
        if (null !== $returnCurve->unavailableReason) {
            return OpenRetentionAssessment::unavailable($returnCurve->unavailableReason, $grossRate, $returnCurve);
        }
        if (null === $returnCurve->source) {
            return OpenRetentionAssessment::unavailable('return_training_row_missing', $grossRate, $returnCurve);
        }
        if (null !== $returnCurve->source->budgetFailure) {
            return OpenRetentionAssessment::unavailable('input_budget_exceeded', $grossRate, $returnCurve);
        }
        if ($returnCurve->source->excludedGroups < 0) {
            return OpenRetentionAssessment::unavailable('invalid_return_training_counts', $grossRate, $returnCurve);
        }
        if (null === $returnCurve->curve) {
            return OpenRetentionAssessment::unavailable('return_training_profile_missing', $grossRate, $returnCurve);
        }
        $returnBatch = new NetTrainingBatch($companyId, $accountId, $returnCurve->readAt, $returnCurve->context, [], [$returnCurve->source]);
        $rebuiltReturn = $this->curves->build($companyId, $accountId, $returnBatch, $returnCurve->marketplaceSku);
        if (null === $rebuiltReturn->curve) {
            return OpenRetentionAssessment::unavailable($rebuiltReturn->unavailableReason ?? 'return_training_profile_missing', $grossRate, $returnCurve);
        }
        if (!$this->sameCurve($rebuiltReturn->curve, $returnCurve->curve)) {
            return OpenRetentionAssessment::unavailable('invalid_return_curve', $grossRate, $returnCurve);
        }
        $cumulative = $rebuiltReturn->curve->cumulativeAt(ObservedReturnCurve::HORIZON);
        if (null === $cumulative->probability) {
            return OpenRetentionAssessment::unavailable($cumulative->unavailableReason ?? 'return_curve_unavailable', $grossRate, $returnCurve);
        }

        return OpenRetentionAssessment::available(OpenRetainedQuantities::fromProbabilities($target->eligibleOpenQuantity, $probability, $cumulative->probability), $probability, $cumulative->probability, $basis, $grossRate, $returnCurve);
    }

    private function sameContext(NetTrainingContext $context, NetTrainingContext $target): bool
    {
        return $context->fingerprint() === $target->fingerprint()
            && $context->epoch->start->format('U.u') === $target->epoch->start->format('U.u')
            && $context->epoch->end?->format('U.u') === $target->epoch->end?->format('U.u');
    }

    private function sameCurve(ObservedReturnCurve $rebuilt, ObservedReturnCurve $cached): bool
    {
        if ($rebuilt->sampleQuantity !== $cached->sampleQuantity || $rebuilt->samplePostings !== $cached->samplePostings) {
            return false;
        }
        foreach ($rebuilt->points as $age => $point) {
            $other = $cached->points[$age];
            if ($point->age !== $other->age || $point->returnedQuantity !== $other->returnedQuantity
                || $point->atRiskQuantity !== $other->atRiskQuantity || $point->atRiskPostingsLowerBound !== $other->atRiskPostingsLowerBound) {
                return false;
            }
        }

        return true;
    }
}
