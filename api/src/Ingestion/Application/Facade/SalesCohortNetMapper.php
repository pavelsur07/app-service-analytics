<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

use App\Ingestion\Application\NetForecast\NetForecastBatch;
use App\Ingestion\Application\NetForecast\NetForecastScope;

/** @phpstan-type NetPoints array<string, array<string, array{period: SalesCohortNetValues, cumulative: SalesCohortNetValues}>> */
final readonly class SalesCohortNetMapper
{
    /** @return NetPoints */
    public function map(NetForecastBatch $batch, SalesCohortRequest $request): array
    {
        if (null === $batch->series || null !== $batch->series->unavailableReason) {
            return [];
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

        return $points;
    }

    private static function value(NetForecastScope $scope): SalesCohortNetValues
    {
        $trainingScopes = array_values(array_unique(array_intersect($scope->basis, ['sku', 'account'])));
        sort($trainingScopes, \SORT_STRING);
        $reasons = array_values(array_unique($scope->reasons));
        sort($reasons, \SORT_STRING);

        return new SalesCohortNetValues($scope->quantities?->expectedNetDecimal(), $scope->expectedNetRevenue,
            $scope->unavailableReason, $scope->moneyUnavailableReason,
            new SalesCohortNetQuality($scope->unavailableReason, $scope->moneyUnavailableReason, $trainingScopes, $reasons, 'release_approved', 'assessed'));
    }
}
