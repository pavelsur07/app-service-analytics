<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Facade;

use App\Ingestion\Application\Facade\SalesCohortNetMapper;
use App\Ingestion\Application\Facade\SalesCohortRequest;
use App\Ingestion\Application\NetForecast\NetForecastBatch;
use App\Ingestion\Application\NetForecast\NetForecastScope;
use App\Ingestion\Application\NetForecast\NetForecastSeries;
use App\Ingestion\Application\NetForecast\NetForecastSeriesPoint;
use App\Ingestion\Application\NetForecast\NetForecastSeriesSku;
use App\Ingestion\Domain\ValueObject\NetForecastQuantities;
use App\Shared\Domain\ValueObject\Money;
use PHPUnit\Framework\TestCase;

final class SalesCohortNetMapperTest extends TestCase
{
    public function testExactNetAndIndependentMoneyReasonWithStableSelectedQuality(): void
    {
        $mapped = (new SalesCohortNetMapper())->map(self::batch(), self::request());
        $value = $mapped['SKU']['2026-08-01|2026-08-01']['period'];
        self::assertSame('90.000', $value->quantity);
        self::assertSame(9000, $value->revenue?->minorAmount());
        self::assertNull($value->reason);
        self::assertNull($value->moneyReason);
        self::assertSame(['account', 'sku'], $value->quality->trainingScopes);
        self::assertSame(['insufficient_sample'], $value->quality->componentReasons);
        $moneyMissing = (new SalesCohortNetMapper())->map(self::batch(missingMoney: true), self::request())['SKU']['2026-08-01|2026-08-01']['period'];
        self::assertSame('90.000', $moneyMissing->quantity);
        self::assertNull($moneyMissing->reason);
        self::assertNull($moneyMissing->revenue);
        self::assertSame('money_price_missing', $moneyMissing->quality->sourceMoneyUnavailableReason);
    }

    public function testRepeatedScopesPreserveIndependentMoneyQualityZeroAndUnavailableValues(): void
    {
        $base = self::batch();
        $ready = $base->series?->skus[0]->points[0]->period;
        self::assertNotNull($ready);
        $missing = new NetForecastScope($ready->quantities, null, null, 'money_price_missing', ['account'], ['insufficient_sample']);
        $differentMoney = new NetForecastScope($ready->quantities, Money::ofMinor(1, 'USD'), null, null, ['sku'], ['price_source']);
        $zero = new NetForecastScope(NetForecastQuantities::zero(), Money::ofMinor(0, 'JPY'), null, null, [], []);
        $unavailable = new NetForecastScope(null, null, 'unknown_outcome', 'quantity_unavailable', [], ['unknown_outcome']);
        $series = new NetForecastSeries([new NetForecastSeriesSku('SKU', [
            new NetForecastSeriesPoint('2026-08-01', '2026-08-01', $ready, $ready),
            new NetForecastSeriesPoint('2026-08-02', '2026-08-02', $missing, $unavailable),
            new NetForecastSeriesPoint('2026-08-03', '2026-08-03', $zero, $missing),
            new NetForecastSeriesPoint('2026-08-04', '2026-08-04', $differentMoney, $ready),
        ])]);
        $batch = new NetForecastBatch('company', 'account', $base->readAt, null, [], $base->quantities, null, $base->expectedNetRevenue, null, $series,
            $base->computeDeadlineNs, $base->allocationBaselineBytes, $base->outputBytesUsed, $base->allocationLimitBytes, $base->peakLimitBytes, $base->outputLimitBytes);
        $request = new SalesCohortRequest(['SKU'], new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-04'), new \DateTimeImmutable('2026-08-01'));
        $mapped = (new SalesCohortNetMapper())->map($batch, $request)['SKU'];
        self::assertSame('90.000', $mapped['2026-08-01|2026-08-01']['period']->quantity);
        self::assertSame(9000, $mapped['2026-08-04|2026-08-04']['cumulative']->revenue?->minorAmount());
        self::assertNull($mapped['2026-08-02|2026-08-02']['period']->revenue);
        self::assertSame('money_price_missing', $mapped['2026-08-03|2026-08-03']['cumulative']->quality->sourceMoneyUnavailableReason);
        self::assertSame(['account'], $mapped['2026-08-02|2026-08-02']['period']->quality->trainingScopes);
        self::assertNull($mapped['2026-08-02|2026-08-02']['cumulative']->quantity);
        self::assertSame('unknown_outcome', $mapped['2026-08-02|2026-08-02']['cumulative']->reason);
        self::assertSame('0.000', $mapped['2026-08-03|2026-08-03']['period']->quantity);
        self::assertSame('JPY', $mapped['2026-08-03|2026-08-03']['period']->revenue?->currency());
        self::assertSame(0, $mapped['2026-08-03|2026-08-03']['period']->revenue->minorAmount());
        self::assertSame('USD', $mapped['2026-08-04|2026-08-04']['period']->revenue?->currency());
        self::assertSame(1, $mapped['2026-08-04|2026-08-04']['period']->revenue->minorAmount());
        self::assertSame(['price_source'], $mapped['2026-08-04|2026-08-04']['period']->quality->componentReasons);
    }

    public function testExpiredActualDeadlineCannotPublishAnyNet(): void
    {
        $this->expectException(\OverflowException::class);
        (new SalesCohortNetMapper())->map(self::batch(deadline: hrtime(true) - 1), self::request());
    }

    public function testOutputBudgetIsSharedAndSecondScopeCannotLeakFirstScope(): void
    {
        $batch = self::batch();
        $mapped = (new SalesCohortNetMapper())->map($batch, self::request());
        $value = $mapped['SKU']['2026-08-01|2026-08-01']['period'];
        $firstScopeBytes = \strlen(json_encode(['expectedNetSalesQuantity' => $value->quantity,
            'expectedNetRevenue' => ['minor' => '9000', 'currency' => 'RUB', 'fractionDigits' => 2],
            'netForecastUnavailableReason' => null, 'netForecastMoneyUnavailableReason' => null,
            'netForecastQuality' => $value->quality], \JSON_THROW_ON_ERROR));
        // The first scope fits; cumulative must still be charged and reject the whole result.
        $this->expectException(\OverflowException::class);
        (new SalesCohortNetMapper())->map(self::batch(outputLimit: 500 + $firstScopeBytes + 100), self::request());
    }

    public function testExistingOutputExhaustionCannotPublish(): void
    {
        $this->expectException(\OverflowException::class);
        (new SalesCohortNetMapper())->map(self::batch(outputLimit: 500), self::request());
    }

    private static function request(): SalesCohortRequest
    {
        $date = new \DateTimeImmutable('2026-08-01');

        return new SalesCohortRequest(['SKU'], $date, $date, $date);
    }

    private static function batch(?int $deadline = null, int $outputLimit = 33554432, bool $missingMoney = false): NetForecastBatch
    {
        $quantities = NetForecastQuantities::fromComponents('70', '50', '5', '1', '25', '1');
        $scope = new NetForecastScope($quantities, $missingMoney ? null : Money::ofMinor(9000, 'RUB'), null, $missingMoney ? 'money_price_missing' : null, ['sku', 'mature_target', 'account', 'sku'], ['insufficient_sample', 'insufficient_sample']);

        return new NetForecastBatch('company', 'account', new \DateTimeImmutable('2026-08-02'), null, [], $quantities, null, $scope->expectedNetRevenue, $scope->moneyUnavailableReason,
            new NetForecastSeries([new NetForecastSeriesSku('SKU', [new NetForecastSeriesPoint('2026-08-01', '2026-08-01', $scope, $scope)])]),
            computeDeadlineNs: $deadline ?? (int) (hrtime(true) + 5000000000), allocationBaselineBytes: memory_get_usage(true), outputBytesUsed: 500,
            allocationLimitBytes: 50331648, peakLimitBytes: 100663296, outputLimitBytes: $outputLimit);
    }
}
