<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\ObservedReturnCurve;
use App\Ingestion\Domain\ValueObject\ReturnCurvePoint;
use App\Ingestion\Infrastructure\Query\NetForecast\ReturnTrainingAgeRow;
use App\Ingestion\Infrastructure\Query\NetForecast\ReturnTrainingAggregateRow;

/** Maps one explicitly chosen training scope; fallback belongs to its caller. */
final readonly class BuildReturnCurveAction
{
    public function build(string $companyId, string $accountId, NetTrainingBatch $batch, ?string $marketplaceSku = null): ScopedReturnCurve
    {
        if ($batch->companyId !== $companyId || $batch->accountId !== $accountId) {
            throw new \InvalidArgumentException('Training batch belongs to another company or account.');
        }
        $batch->context?->assertScope($companyId, $accountId);
        if (null !== $batch->unavailableReason) {
            return $this->unavailable($batch, $marketplaceSku, null, $batch->unavailableReason);
        }
        if (null === $batch->context) {
            return $this->unavailable($batch, $marketplaceSku, null, 'training_context_missing');
        }
        if ($batch->readAt->format('U.u') !== $batch->context->readAt->format('U.u')) {
            return $this->unavailable($batch, $marketplaceSku, null, 'training_context_mismatch');
        }
        $selected = array_values(array_filter($batch->returns, static fn (ReturnTrainingAggregateRow $row): bool => $row->marketplaceSku === $marketplaceSku));
        if (1 !== \count($selected)) {
            return $this->unavailable($batch, $marketplaceSku, null, [] === $selected ? 'return_training_row_missing' : 'return_training_row_ambiguous');
        }
        $source = $selected[0];
        if (!$source->inputComplete || null !== $source->unavailableReason) {
            return $this->unavailable($batch, $marketplaceSku, $source, $source->unavailableReason ?? 'return_training_input_incomplete');
        }
        if ('posting_capacity_lower_bound_v1' !== $source->riskPostingMethod) {
            return $this->unavailable($batch, $marketplaceSku, $source, 'unsupported_risk_posting_method');
        }
        if (null === $source->ageProfile || null === $source->sampleQuantity || null === $source->samplePostings) {
            return $this->unavailable($batch, $marketplaceSku, $source, 'return_training_profile_missing');
        }
        try {
            $points = array_map(static fn (ReturnTrainingAgeRow $point): ReturnCurvePoint => new ReturnCurvePoint($point->age, $point->returnedQuantity, $point->atRiskQuantity, $point->atRiskPostingsLowerBound), $source->ageProfile);
            $curve = new ObservedReturnCurve($source->sampleQuantity, $source->samplePostings, $points);
        } catch (\InvalidArgumentException) {
            return $this->unavailable($batch, $marketplaceSku, $source, 'invalid_return_training_profile');
        }

        return new ScopedReturnCurve($companyId, $accountId, $batch->readAt, $batch->context, $marketplaceSku, $source, $curve, null);
    }

    private function unavailable(NetTrainingBatch $batch, ?string $marketplaceSku, ?ReturnTrainingAggregateRow $source, string $reason): ScopedReturnCurve
    {
        return new ScopedReturnCurve($batch->companyId, $batch->accountId, $batch->readAt, $batch->context, $marketplaceSku, $source, null, $reason);
    }
}
