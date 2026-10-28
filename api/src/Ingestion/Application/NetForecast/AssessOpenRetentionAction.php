<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;

/** Resolves one whole current-RR open estimate: SKU, own account, unavailable. */
final readonly class AssessOpenRetentionAction
{
    public function __construct(private BuildPreHandoverGrossRateAction $preRates, private BuildPostHandoverGrossRateAction $postRates, private BuildReturnCurveAction $curves, private ApplyOpenRetentionAction $apply)
    {
    }

    public function assess(string $companyId, string $accountId, OpenTargetEvidence $target, NetTrainingBatch $batch): OpenRetentionResolution
    {
        $target->context->assertScope($companyId, $accountId);
        if ($batch->companyId !== $companyId || $batch->accountId !== $accountId) {
            throw new \InvalidArgumentException('Training batch belongs to another company or account.');
        }
        $batch->context?->assertScope($companyId, $accountId);
        if (null === $target->context->snapshotId || '' === trim($target->context->snapshotId) || 'single_statement' === $target->context->snapshotId) {
            return OpenRetentionResolution::unavailable('target_snapshot_missing');
        }
        if ($batch->readAt->format('U.u') !== $target->context->readAt->format('U.u')
            || (null !== $batch->context && !$this->sameContext($batch->context, $target->context))) {
            return OpenRetentionResolution::unavailable('training_context_mismatch');
        }
        if (null !== $target->unavailableReason || !$target->inputComplete
            || !\in_array($target->stage, [OpenTargetEvidence::BEFORE_HANDOVER, OpenTargetEvidence::AFTER_HANDOVER], true)
            || null === $target->eligibleOpenQuantity || 0 === $target->eligibleOpenQuantity) {
            $assessment = $this->apply->apply($companyId, $accountId, $target, null, null);
            if (null !== $assessment->quantities) {
                return OpenRetentionResolution::knownZero($assessment);
            }
            \assert(null !== $assessment->unavailableReason);

            return OpenRetentionResolution::unavailable($assessment->unavailableReason);
        }
        if (null === $batch->context) {
            return OpenRetentionResolution::unavailable('training_context_missing');
        }
        $attempts = [];
        foreach ([$target->marketplaceSku, null] as $sku) {
            $gross = OpenTargetEvidence::BEFORE_HANDOVER === $target->stage
                ? $this->preRates->build($companyId, $accountId, $batch, $sku)
                : $this->postRates->build($companyId, $accountId, $batch, $sku);
            $returns = $this->curves->build($companyId, $accountId, $batch, $sku);
            $assessment = $this->apply->apply($companyId, $accountId, $target, $gross, $returns);
            $attempts[] = new OpenRetentionAttempt($sku, $assessment);
            if (null !== $assessment->quantities) {
                return OpenRetentionResolution::available($assessment, $attempts);
            }
        }

        return OpenRetentionResolution::unavailable('open_retention_unavailable', $attempts);
    }

    private function sameContext(NetTrainingContext $context, NetTrainingContext $target): bool
    {
        return $context->fingerprint() === $target->fingerprint()
            && $context->epoch->start->format('U.u') === $target->epoch->start->format('U.u')
            && $context->epoch->end?->format('U.u') === $target->epoch->end?->format('U.u');
    }
}
