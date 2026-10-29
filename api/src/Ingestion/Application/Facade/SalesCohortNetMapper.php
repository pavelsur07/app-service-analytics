<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

use App\Ingestion\Application\NetForecast\NetForecastBatch;
use App\Ingestion\Application\NetForecast\NetForecastScope;
use App\Ingestion\Domain\ValueObject\NetForecastFutureT1;

/** @phpstan-type NetPoints array<string, array<string, array{period: SalesCohortNetValues, cumulative: SalesCohortNetValues}>> */
final readonly class SalesCohortNetMapper
{
    public function map(NetForecastBatch $batch, SalesCohortRequest $request): SalesCohortNetProjection
    {
        if (null === $batch->series || null !== $batch->series->unavailableReason) {
            return new SalesCohortNetProjection([], null);
        }
        $expected = $request->marketplaceSkus;
        $actual = array_column($batch->series->skus, 'marketplaceSku');
        sort($expected, \SORT_STRING);
        sort($actual, \SORT_STRING);
        if ($expected !== $actual) {
            throw new \UnexpectedValueException('Net series must describe the exact requested SKU set.');
        }
        $used = $batch->outputBytesUsed ?? throw new \OverflowException('Missing publication budget.');
        $guard = static function () use ($batch, &$used): void {
            if (null === $batch->computeDeadlineNs || null === $batch->allocationBaselineBytes
                || null === $batch->allocationLimitBytes || null === $batch->peakLimitBytes || null === $batch->outputLimitBytes
                || $used < 0 || $used > min(33554432, $batch->outputLimitBytes)
                || hrtime(true) > $batch->computeDeadlineNs
                || memory_get_peak_usage(true) > min(100663296, $batch->peakLimitBytes)
                || memory_get_usage(true) + 8388608 > min(100663296, $batch->peakLimitBytes)
                || memory_get_usage(true) - $batch->allocationBaselineBytes + 8388608 > min(50331648, $batch->allocationLimitBytes)) {
                throw new \OverflowException('Net publication budget exceeded.');
            }
        };
        $guard();
        $points = [];
        /** @var \WeakMap<NetForecastScope, array{value: SalesCohortNetValues, bytes: int}> $values */
        $values = new \WeakMap();
        foreach ($batch->series->skus as $sku) {
            foreach ($sku->points as $point) {
                $mapped = [];
                foreach (['period' => $point->period, 'cumulative' => $point->cumulative] as $kind => $scope) {
                    $guard();
                    if (!isset($values[$scope])) {
                        $value = self::value($scope);
                        // Charge only the new public scalar projection; never serialize the private graph.
                        $bytes = \strlen(json_encode([
                            'expectedNetSalesQuantity' => $value->quantity,
                            'expectedNetRevenue' => null === $value->revenue ? null : ['minor' => (string) $value->revenue->minorAmount(), 'currency' => $value->revenue->currency(), 'fractionDigits' => $value->revenue->fractionDigits()],
                            'netForecastUnavailableReason' => $value->reason,
                            'netForecastMoneyUnavailableReason' => $value->moneyReason,
                            'netForecastQuality' => $value->quality,
                            'goalForecast' => $value->goalForecast,
                        ], \JSON_THROW_ON_ERROR));
                        $values[$scope] = ['value' => $value, 'bytes' => $bytes];
                    }
                    $value = $values[$scope]['value'];
                    // Every occurrence consumes output budget, including reused immutable scopes.
                    $used += $values[$scope]['bytes'];
                    $guard();
                    $mapped[$kind] = $value;
                }
                $used += \strlen(json_encode([$sku->marketplaceSku, $point->bucketFrom, $point->bucketTo], \JSON_THROW_ON_ERROR)) + 32;
                $guard();
                $points[$sku->marketplaceSku][$point->bucketFrom.'|'.$point->bucketTo] = ['period' => $mapped['period'], 'cumulative' => $mapped['cumulative']];
            }
        }

        $guard();

        $provenance = $batch->futureT1Provenance();
        $used += \strlen(json_encode(['goalForecastMethodVersion' => NetForecastFutureT1::METHOD_VERSION, 'goalForecastProvenance' => $provenance], \JSON_THROW_ON_ERROR));
        $guard();
        \assert(null !== $batch->computeDeadlineNs && null !== $batch->allocationBaselineBytes && null !== $batch->allocationLimitBytes && null !== $batch->peakLimitBytes && null !== $batch->outputLimitBytes);
        $budget = new SalesCohortForecastBudget($batch->computeDeadlineNs, $batch->allocationBaselineBytes, $used,
            min(50331648, $batch->allocationLimitBytes), min(100663296, $batch->peakLimitBytes), min(33554432, $batch->outputLimitBytes));
        $projection = new SalesCohortNetProjection($points, $budget, NetForecastFutureT1::METHOD_VERSION, $provenance);
        $guard();

        return $projection;
    }

    private static function value(NetForecastScope $scope): SalesCohortNetValues
    {
        $trainingScopes = array_values(array_unique(array_intersect($scope->basis, ['sku', 'account'])));
        sort($trainingScopes, \SORT_STRING);
        $reasons = array_values(array_unique($scope->reasons));
        sort($reasons, \SORT_STRING);

        return new SalesCohortNetValues($scope->quantities?->expectedNetDecimal(), $scope->expectedNetRevenue,
            $scope->unavailableReason, $scope->moneyUnavailableReason,
            new SalesCohortNetQuality($scope->unavailableReason, $scope->moneyUnavailableReason, $trainingScopes, $reasons, 'release_approved', 'assessed'),
            new SalesCohortGoalForecast($scope->quantities?->expectedNetNumerator, $scope->quantities?->expectedNetDenominator,
                null === $scope->quantities ? ($scope->unavailableReason ?? 'quantity_unavailable') : null,
                $scope->futureT1->numerator(), $scope->futureT1->denominator(), $scope->futureT1->unavailableReason));
    }
}
