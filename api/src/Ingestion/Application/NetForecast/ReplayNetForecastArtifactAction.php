<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\NetForwardArtifactKind;
use App\Ingestion\Domain\ValueObject\ObservedReturnCurve;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetBatch;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetDayRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetLimits;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetRequest;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetSkuRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingBudgetFailure;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;

/** Pure arithmetic replay after verified artifact IO; no source query or current clock. */
final readonly class ReplayNetForecastArtifactAction
{
    public function __construct(private NetForwardArtifactReader $reader, private NetForwardInputCodec $inputs, private AggregateNetForecastAction $aggregate, private NetForwardManifestCodec $codec)
    {
    }

    public function __invoke(string $companyId, string $accountId, string $requestId, string $artifactHash): NetForecastBatch
    {
        $artifact = $this->reader->read($companyId, $accountId, NetForwardArtifactKind::Input, $requestId, $artifactHash);
        $metadata = $artifact->manifest['metadata'];
        if (!\is_array($metadata) || ($metadata['model'] ?? null) !== ObservedReturnCurve::MODEL_VERSION || ($metadata['training'] ?? null) !== NetTrainingContext::MODEL_VERSION || ($metadata['coverage'] ?? null) !== NetTrainingContext::COVERAGE_POLICY || !\is_string($metadata['groupBy'] ?? null)) {
            throw new \UnexpectedValueException('Unsupported replay methodology.');
        }
        $training = null;
        $request = null;
        $targetMetadata = null;
        $summary = null;
        $days = [];
        $evidence = 0;
        $baseline = 0;
        $forecastDays = 0;
        $allocation = memory_get_usage(true);
        $started = hrtime(true);
        foreach ($this->reader->segments($companyId, $accountId, $artifact) as $segment) {
            if (memory_get_usage(true) - $allocation + 8388608 > 50331648 || memory_get_usage(true) + 8388608 > 100663296) {
                throw new \OverflowException('Replay input memory budget exceeded.');
            }
            foreach ($segment['rows'] as $row) {
                switch ($segment['type']) {
                    case 'training':
                        if (null !== $training) {
                            throw new \UnexpectedValueException('Duplicate training frame.');
                        }
                        $training = $this->inputs->unpack($row['value'] ?? null);
                        if (!$training instanceof NetTrainingBatch) {
                            throw new \UnexpectedValueException('Missing typed training frame.');
                        }
                        break;
                    case 'target_request':
                        if (null !== $request) {
                            throw new \UnexpectedValueException('Duplicate request frame.');
                        }
                        $request = $this->inputs->unpack($row['value'] ?? null);
                        if (!$request instanceof NetTargetRequest) {
                            throw new \UnexpectedValueException('Missing typed request frame.');
                        }
                        break;
                    case 'target_metadata':
                        if (null !== $targetMetadata) {
                            throw new \UnexpectedValueException('Duplicate target metadata.');
                        }
                        $targetMetadata = $row;
                        break;
                    case 'target_day':
                        $day = $this->inputs->unpack($row['value'] ?? null);
                        if (!$day instanceof NetTargetDayRow || !\is_string($row['sku'] ?? null) || isset($days[$row['sku']][$day->businessDate])) {
                            throw new \UnexpectedValueException('Invalid or duplicate target day.');
                        }
                        $days[$row['sku']][$day->businessDate] = $day;
                        if (\count($days) > 50 || \count($days[$row['sku']]) > 184) {
                            throw new \OverflowException('Replay target bound exceeded.');
                        }
                        break;
                    case 'forecast_summary':
                        if (null !== $summary) {
                            throw new \UnexpectedValueException('Duplicate forecast summary.');
                        }
                        $summary = $row;
                        break;
                    case 'forecast_day':
                        ++$forecastDays;
                        break;
                    case 'evidence':
                        ++$evidence;
                        break;
                    case 'simple_baseline':
                        ++$baseline;
                        break;
                }
            }
        }
        if (null === $training || null === $request || null === $summary || 1 !== $baseline || $training->companyId !== $companyId || $training->accountId !== $accountId || $training->readAt->format('U.u') !== $artifact->readAt->format('U.u')) {
            throw new \UnexpectedValueException('Incomplete replay frame.');
        }
        if (null === $training->context) {
            return new NetForecastBatch($companyId, $accountId, $training->readAt, null, [], null, $training->unavailableReason, null, $training->unavailableReason);
        }
        if (0 === $evidence || null === $targetMetadata || !\array_key_exists('unavailableReason', $targetMetadata) || (null !== $targetMetadata['unavailableReason'] && !\is_string($targetMetadata['unavailableReason']))) {
            throw new \UnexpectedValueException('Replay requires actual evidence and target metadata.');
        }
        $sealedRefusal = $summary['reason'] ?? null;
        if (\in_array($sealedRefusal, ['input_budget_exceeded', 'query_budget_exceeded'], true)) {
            $refusalSummary = ['quantities' => $this->inputs->pack(null), 'revenue' => $this->inputs->pack(null), 'reason' => $sealedRefusal, 'moneyReason' => $sealedRefusal, 'futureT1Policy' => 'pre_t1_gross_first_valid_v1', 'futureT1Provenance' => [], 'series' => $this->inputs->pack(null)];
            if (0 !== $forecastDays || $this->codec->encode($refusalSummary) !== $this->codec->encode($summary)
                || ('query_budget_exceeded' === $sealedRefusal && 'query_budget_exceeded' !== $targetMetadata['unavailableReason'])) {
                throw new \UnexpectedValueException('Invalid sealed forecast refusal.');
            }
            if (hrtime(true) - $started > 30000000000) {
                throw new \OverflowException('Replay wall budget exceeded.');
            }

            // No numeric forecast exists to recompute. Preserve the capture's sealed refusal
            // instead of granting replay a fresh target query or compute budget.
            return new NetForecastBatch($companyId, $accountId, $training->readAt, $training->context, [], null, $sealedRefusal, null, $sealedRefusal);
        }
        $failure = $this->inputs->unpack($targetMetadata['budgetFailure'] ?? null);
        if (null !== $failure && !$failure instanceof NetTrainingBudgetFailure) {
            throw new \UnexpectedValueException('Invalid target budget failure.');
        }
        $skus = [];
        foreach ($request->marketplaceSkus as $sku) {
            $skuDays = $days[$sku] ?? [];
            ksort($skuDays);
            $skus[] = new NetTargetSkuRow($sku, array_values($skuDays));
        }
        $targets = new NetTargetBatch($training->context, $skus, $targetMetadata['unavailableReason'], $failure);
        // IO and decoding have their own bounded artifact budget; model starts here.
        // Original capture used its original frame budget and saved that outcome.
        $result = ($this->aggregate)($companyId, $accountId, $targets, $training, hrtime(true), $allocation, new NetTargetLimits(), new NetForecastSeriesRequest($request->marketplaceSkus, $request->from, $request->to, $request->accumulationFrom, $metadata['groupBy']));
        $actual = ['quantities' => $this->inputs->pack($result->quantities), 'revenue' => $this->inputs->pack($result->expectedNetRevenue), 'reason' => $result->unavailableReason, 'moneyReason' => $result->moneyUnavailableReason, 'futureT1Policy' => 'pre_t1_gross_first_valid_v1', 'futureT1Provenance' => $result->futureT1Provenance(), 'series' => $this->inputs->pack($result->series)];
        if ($this->codec->encode($actual) !== $this->codec->encode($summary)) {
            throw new \UnexpectedValueException('Sealed forecast replay differs.');
        }
        if (hrtime(true) - $started > 30000000000) {
            throw new \OverflowException('Replay wall budget exceeded.');
        }

        return $result;
    }
}
