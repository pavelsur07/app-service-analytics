<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\PostHandoverGrossSample;
use App\Ingestion\Domain\ValueObject\PreHandoverGrossSample;
use App\Ingestion\Infrastructure\Query\NetForecast\GrossTrainingAggregateRow;

/** Maps one complete training row; stage-specific fallback belongs to its caller. */
final readonly class BuildPostHandoverGrossRateAction
{
    public function build(string $companyId, string $accountId, NetTrainingBatch $batch, ?string $marketplaceSku = null): ScopedPostHandoverGrossRate
    {
        if ($batch->companyId !== $companyId || $batch->accountId !== $accountId) {
            throw new \InvalidArgumentException('Training batch belongs to another company or account.');
        }
        $batch->context?->assertScope($companyId, $accountId);
        if (null === $batch->context) {
            return $this->unavailable($batch, $marketplaceSku, null, 'training_context_missing');
        }
        if (null === $batch->context->snapshotId || '' === trim($batch->context->snapshotId) || 'single_statement' === $batch->context->snapshotId) {
            return $this->unavailable($batch, $marketplaceSku, null, 'training_snapshot_missing');
        }
        if ($batch->readAt->format('U.u') !== $batch->context->readAt->format('U.u')) {
            return $this->unavailable($batch, $marketplaceSku, null, 'training_context_mismatch');
        }
        if (null !== $batch->unavailableReason) {
            return $this->unavailable($batch, $marketplaceSku, null, $batch->unavailableReason);
        }
        $selected = array_values(array_filter($batch->gross, static fn (GrossTrainingAggregateRow $row): bool => $row->marketplaceSku === $marketplaceSku));
        if (1 !== \count($selected)) {
            return $this->unavailable($batch, $marketplaceSku, null, [] === $selected ? 'gross_training_row_missing' : 'gross_training_row_ambiguous');
        }
        $source = $selected[0];
        if (null !== $source->budgetFailure) {
            return $this->unavailable($batch, $marketplaceSku, $source, 'input_budget_exceeded');
        }
        if (!$source->inputComplete || null !== $source->unavailableReason) {
            return $this->unavailable($batch, $marketplaceSku, $source, $source->unavailableReason ?? 'gross_training_input_incomplete');
        }
        if (null === $source->p95Seconds) {
            return $this->unavailable($batch, $marketplaceSku, $source, 'maturity_unavailable');
        }
        if ($source->p95Seconds < 0 || $source->excludedGroups < 0 || $source->excludedDays < 0) {
            return $this->unavailable($batch, $marketplaceSku, $source, 'invalid_gross_training_counts');
        }
        if (null === $source->sampleQuantity || null === $source->samplePostings || null === $source->grossQuantity
            || null === $source->t1Quantity || null === $source->t2Quantity || null === $source->pQuantity || null === $source->postHandoverPostings) {
            return $this->unavailable($batch, $marketplaceSku, $source, 'gross_training_counts_missing');
        }
        try {
            $fullSample = new PreHandoverGrossSample($source->sampleQuantity, $source->samplePostings, $source->grossQuantity, $source->t1Quantity, $source->t2Quantity, $source->pQuantity);
            $sample = new PostHandoverGrossSample($fullSample, $source->postHandoverPostings);
        } catch (\InvalidArgumentException) {
            return $this->unavailable($batch, $marketplaceSku, $source, 'invalid_gross_training_counts');
        }
        $probability = $sample->probability();

        return new ScopedPostHandoverGrossRate($companyId, $accountId, $batch->readAt, $batch->context, $marketplaceSku, $source, $probability, null === $probability ? 'insufficient_sample' : null);
    }

    private function unavailable(NetTrainingBatch $batch, ?string $marketplaceSku, ?GrossTrainingAggregateRow $source, string $reason): ScopedPostHandoverGrossRate
    {
        return new ScopedPostHandoverGrossRate($batch->companyId, $batch->accountId, $batch->readAt, $batch->context, $marketplaceSku, $source, null, $reason);
    }
}
