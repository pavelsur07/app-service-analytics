<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Application\NetForecast\NetForecastBatch;
use App\Ingestion\Application\NetForecast\NetForecastDay;
use App\Ingestion\Application\NetForecast\NetForecastSeriesRequest;
use App\Ingestion\Application\NetForecast\NetForecastSku;
use App\Ingestion\Application\NetForecast\ProjectNetForecastSeriesAction;
use App\Ingestion\Domain\ValueObject\NetForecastQuantities;
use App\Ingestion\Domain\ValueObject\NetForecastRevenue;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetRequest;
use PHPUnit\Framework\TestCase;

final class ProjectNetForecastSeriesActionTest extends TestCase
{
    protected function setUp(): void
    {
        self::assertTrue(class_exists(ProjectNetForecastSeriesAction::class), 'Bounded exact series projector must exist.');
    }

    public function testHiddenThirdCarriesIntoCumulativeButNotVisiblePeriod(): void
    {
        $days = array_map(self::day(...), ['2026-08-01', '2026-08-02', '2026-08-03']);
        $series = (new ProjectNetForecastSeriesAction())(self::batch($days), self::request('2026-08-02', '2026-08-03', 'day', '2026-08-01'), static function (): void {});
        self::assertNull($series->unavailableReason);
        $points = $series->skus[0]->points;
        self::assertCount(2, $points);
        self::assertSame('0.333', $points[0]->period->quantities?->expectedNetDecimal());
        self::assertSame('1.000', $points[1]->cumulative->quantities?->expectedNetDecimal());
        self::assertSame(1, $points[1]->cumulative->expectedNetRevenue?->minorAmount());
        self::assertSame(0, $points[0]->period->expectedNetRevenue?->minorAmount());
        self::assertSame(0, $points[1]->period->expectedNetRevenue?->minorAmount());
        $period = (new ProjectNetForecastSeriesAction())(self::batch($days), self::request('2026-08-02', '2026-08-03', 'period', '2026-08-01'), static function (): void {})->skus[0]->points[0];
        self::assertSame('0.667', $period->period->quantities?->expectedNetDecimal());
        self::assertSame(1, $period->period->expectedNetRevenue?->minorAmount());
    }

    public function testHalfMinorChildrenAreNotAddedAfterRounding(): void
    {
        $days = [self::day('2026-08-01', denominator: '2'), self::day('2026-08-02', denominator: '2')];
        self::assertNotNull($days[0]->expectedNetRevenue);
        self::assertNotNull($days[1]->expectedNetRevenue);
        self::assertSame(2, $days[0]->expectedNetRevenue->minorAmount() + $days[1]->expectedNetRevenue->minorAmount());
        $point = (new ProjectNetForecastSeriesAction())(self::batch($days), self::request('2026-08-01', '2026-08-02', 'period'), static function (): void {})->skus[0]->points[0];
        self::assertSame(1, $point->period->expectedNetRevenue?->minorAmount());
    }

    public function testCalendarBucketsAreClippedAndCumulativeIncludesHiddenMonth(): void
    {
        $days = [];
        for ($date = new \DateTimeImmutable('2026-08-01'); $date <= new \DateTimeImmutable('2026-09-02'); $date = $date->modify('+1 day')) {
            $days[] = self::day($date->format('Y-m-d'), denominator: '1');
        }
        foreach ([
            'week' => [['2026-08-30', '2026-08-30'], ['2026-08-31', '2026-09-02']],
            'month' => [['2026-08-30', '2026-08-31'], ['2026-09-01', '2026-09-02']],
            'period' => [['2026-08-30', '2026-09-02']],
        ] as $groupBy => $expected) {
            $points = (new ProjectNetForecastSeriesAction())(self::batch($days), self::request('2026-08-30', '2026-09-02', $groupBy, '2026-08-01'), static function (): void {})->skus[0]->points;
            self::assertSame($expected, array_map(static fn ($point): array => [$point->bucketFrom, $point->bucketTo], $points));
            $last = array_key_last($points);
            self::assertNotNull($last);
            self::assertSame('33.000', $points[$last]->cumulative->quantities?->expectedNetDecimal());
        }
    }

    public function testMonthPrefixAndDivergentScopesPreserveEveryExactFieldAndCurrency(): void
    {
        $days = [self::day('2026-08-30'), self::day('2026-08-31'), self::day('2026-09-01', currency: 'USD'), self::day('2026-09-02', currency: 'USD')];
        $points = (new ProjectNetForecastSeriesAction())(self::batch($days), self::request('2026-08-30', '2026-09-02', 'month'), static function (): void {})->skus[0]->points;
        $twoThirds = NetForecastQuantities::fromComponents('0', '2', '0', '1', '2', '3');
        $fourThirds = NetForecastQuantities::fromComponents('0', '4', '0', '1', '4', '3');
        self::assertNotNull($points[0]->period->quantities);
        self::assertNotNull($points[0]->cumulative->quantities);
        self::assertNotNull($points[1]->period->quantities);
        self::assertNotNull($points[1]->cumulative->quantities);
        self::assertSame(get_object_vars($twoThirds), get_object_vars($points[0]->period->quantities));
        self::assertSame(get_object_vars($twoThirds), get_object_vars($points[0]->cumulative->quantities));
        self::assertSame(get_object_vars($twoThirds), get_object_vars($points[1]->period->quantities));
        self::assertSame(get_object_vars($fourThirds), get_object_vars($points[1]->cumulative->quantities));
        self::assertSame('RUB', $points[0]->cumulative->expectedNetRevenue?->currency());
        self::assertSame('USD', $points[1]->period->expectedNetRevenue?->currency());
        self::assertSame(1, $points[1]->period->expectedNetRevenue->minorAmount());
        self::assertNull($points[1]->cumulative->expectedNetRevenue);
        self::assertNull($points[1]->cumulative->unavailableReason);
        self::assertSame('money_currency_mismatch', $points[1]->cumulative->moneyUnavailableReason);
    }

    public function testHiddenUnavailableDayPoisonsOnlyCumulative(): void
    {
        $days = [self::day('2026-08-01', reason: 'unknown_outcome'), self::day('2026-08-02')];
        $point = (new ProjectNetForecastSeriesAction())(self::batch($days), self::request('2026-08-02', '2026-08-02', 'day', '2026-08-01'), static function (): void {})->skus[0]->points[0];
        self::assertSame('0.333', $point->period->quantities?->expectedNetDecimal());
        self::assertNull($point->cumulative->quantities);
        self::assertNull($point->cumulative->expectedNetRevenue);
        self::assertSame('unknown_outcome', $point->cumulative->unavailableReason);
    }

    public function testMissingDuplicateOrUnrequestedDaysAndSkuNeverBecomeZero(): void
    {
        foreach ([[], [self::day('2026-08-01')], [self::day('2026-08-01'), self::day('2026-08-01')], [self::day('2026-08-01'), self::day('2026-08-03')]] as $days) {
            $series = (new ProjectNetForecastSeriesAction())(self::batch($days), self::request('2026-08-01', '2026-08-02'), static function (): void {});
            self::assertSame('source_history_unavailable', $series->unavailableReason);
            self::assertSame([], $series->skus);
        }
        $batch = self::batch([self::day('2026-08-01')]);
        foreach ([[], ['other'], ['SKU', 'missing']] as $skus) {
            $request = new NetForecastSeriesRequest($skus, new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-01'));
            self::assertSame('source_history_unavailable', (new ProjectNetForecastSeriesAction())($batch, $request, static function (): void {})->unavailableReason);
        }
    }

    public function testMoneyFailureDoesNotInvalidateQuantityAndNativeOverflowCanCancel(): void
    {
        $project = new ProjectNetForecastSeriesAction();
        $request = self::request('2026-08-01', '2026-08-02', 'period');
        $mixed = $project(self::batch([self::day('2026-08-01'), self::day('2026-08-02', currency: 'USD')]), $request, static function (): void {})->skus[0]->points[0]->period;
        self::assertSame('0.667', $mixed->quantities?->expectedNetDecimal());
        self::assertNull($mixed->expectedNetRevenue);
        self::assertSame('money_currency_mismatch', $mixed->moneyUnavailableReason);
        $days = [self::day('2026-08-01', numerator: '2', denominator: '1', price: (string) \PHP_INT_MAX), self::day('2026-08-02', numerator: '1', denominator: '1', price: (string) -\PHP_INT_MAX)];
        self::assertSame('money_overflow', $days[0]->moneyUnavailableReason);
        $scope = $project(self::batch($days), $request, static function (): void {})->skus[0]->points[0]->period;
        self::assertSame(\PHP_INT_MAX, $scope->expectedNetRevenue?->minorAmount());
        $badPrice = $project(self::batch([self::day('2026-08-01', price: null), self::day('2026-08-02')]), $request, static function (): void {})->skus[0]->points[0]->period;
        self::assertSame('0.667', $badPrice->quantities?->expectedNetDecimal());
        self::assertSame('money_price_missing', $badPrice->moneyUnavailableReason);
    }

    public function testRequestPreservesExactSkuStringsAndCalendarBounds(): void
    {
        $from = new \DateTimeImmutable('2026-08-01T20:00:00+03:00');
        $to = new \DateTimeImmutable('2026-08-02');
        $request = new NetForecastSeriesRequest(['01', '1'], $from, $to);
        self::assertSame('2026-08-01T00:00:00+00:00', $request->from->format('c'));
        $request->assertMatches(new NetTargetRequest(['1', '01'], $from, $to));
        foreach ([new NetTargetRequest(['1'], $from, $to), new NetTargetRequest(['01', '1'], $from, $to, $from), new NetTargetRequest(['01', '1'], $from, $to->modify('+1 day'))] as $mismatch) {
            try {
                $request->assertMatches($mismatch);
                self::fail('Exact SKU sets and dates must match.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        foreach ([['SKU', 'SKU'], array_map(strval(...), range(1, 51))] as $skus) {
            try {
                new NetForecastSeriesRequest($skus, $from, $to);
                self::fail('Duplicate or overbound request accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        foreach ([[$from->modify('+1 day'), $from, null, 'day'], [$from, $from->modify('+184 days'), null, 'day'], [$from, $to, $from->modify('-183 days'), 'day'], [$from, $to, null, 'quarter']] as [$start, $end, $accumulation, $group]) {
            try {
                new NetForecastSeriesRequest(['SKU'], $start, $end, $accumulation, $group);
                self::fail('Invalid series calendar accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testEmptyCompleteScopeAndUnavailableEmptyBatchAreDistinct(): void
    {
        $empty = new NetForecastBatch('company', 'account', null, null, [], NetForecastQuantities::zero(), null, null, 'money_currency_missing');
        $request = new NetForecastSeriesRequest([], new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-01'));
        $series = (new ProjectNetForecastSeriesAction())($empty, $request, static function (): void {});
        self::assertSame([], $series->skus);
        self::assertNull($series->unavailableReason);
        $failure = new NetForecastBatch('company', 'account', null, null, [], null, 'input_budget_exceeded', null, 'input_budget_exceeded');
        self::assertSame('input_budget_exceeded', (new ProjectNetForecastSeriesAction())($failure, $request, static function (): void {})->unavailableReason);
        $day = new NetForecastDay('2026-08-01', [], NetForecastQuantities::zero(), null, null, 'money_currency_missing', NetForecastRevenue::empty());
        $scope = (new ProjectNetForecastSeriesAction())(self::batch([$day]), self::request('2026-08-01', '2026-08-01'), static function (): void {})->skus[0]->points[0]->period;
        self::assertSame('0.000', $scope->quantities?->expectedNetDecimal());
        self::assertNull($scope->expectedNetRevenue);
        self::assertSame('money_currency_missing', $scope->moneyUnavailableReason);
    }

    public function testProvenEmptyAndPricedZeroKeepDifferentMoneySemantics(): void
    {
        $zero = NetForecastQuantities::zero();
        $empty = new NetForecastDay('2026-08-01', [], $zero, null, null, 'money_currency_missing', NetForecastRevenue::empty());
        $rub = self::day('2026-08-02', numerator: '0');
        $usd = self::day('2026-08-03', numerator: '0', currency: 'USD');
        $points = (new ProjectNetForecastSeriesAction())(self::batch([$empty, $rub, $usd]), self::request('2026-08-01', '2026-08-03'), static function (): void {})->skus[0]->points;
        self::assertNull($points[0]->period->expectedNetRevenue);
        self::assertSame('money_currency_missing', $points[0]->cumulative->moneyUnavailableReason);
        self::assertSame(0, $points[1]->period->expectedNetRevenue?->minorAmount());
        self::assertSame('RUB', $points[1]->cumulative->expectedNetRevenue?->currency());
        self::assertSame('USD', $points[2]->period->expectedNetRevenue?->currency());
        self::assertSame('0.000', $points[2]->cumulative->quantities?->expectedNetDecimal());
        self::assertNull($points[2]->cumulative->expectedNetRevenue);
        self::assertSame('money_currency_mismatch', $points[2]->cumulative->moneyUnavailableReason);
    }

    public function testAdversarialExactDenominatorsCannotBypassPrecisionBudget(): void
    {
        $days = [self::day('2026-08-01', denominator: '1'.str_repeat('0', 8191).'1'), self::day('2026-08-02', denominator: '1'.str_repeat('0', 8192).'1')];
        $this->expectException(\OverflowException::class);
        (new ProjectNetForecastSeriesAction())(self::batch($days), self::request('2026-08-01', '2026-08-02'), static function (): void {});
    }

    public function testMonthMoneyOverflowIsLocalAndExactCumulativeCanRecover(): void
    {
        $days = [self::day('2026-08-31', numerator: '2', denominator: '1', price: (string) \PHP_INT_MAX), self::day('2026-09-01', denominator: '1', price: (string) -\PHP_INT_MAX)];
        $points = (new ProjectNetForecastSeriesAction())(self::batch($days), self::request('2026-08-31', '2026-09-01', 'month'), static function (): void {})->skus[0]->points;
        self::assertSame('2.000', $points[0]->period->quantities?->expectedNetDecimal());
        self::assertSame('money_overflow', $points[0]->period->moneyUnavailableReason);
        self::assertNull($points[0]->period->expectedNetRevenue);
        self::assertSame(\PHP_INT_MAX, $points[1]->cumulative->expectedNetRevenue?->minorAmount());
        self::assertNull($points[1]->cumulative->moneyUnavailableReason);
        self::assertSame('3.000', $points[1]->cumulative->quantities?->expectedNetDecimal());
    }

    public function testAdversarialFractionsStayInsideTheCallerReducedDeadline(): void
    {
        $days = [self::day('2026-08-01', denominator: '1'.str_repeat('0', 499).'1'), self::day('2026-08-02', denominator: '1'.str_repeat('0', 500).'1')];
        $started = hrtime(true);
        $guard = static function () use ($started): void {
            if (hrtime(true) - $started > 500000) {
                throw new \OverflowException('Reduced shared compute deadline exceeded.');
            }
        };
        $this->expectException(\OverflowException::class);
        (new ProjectNetForecastSeriesAction())(self::batch($days), self::request('2026-08-01', '2026-08-02'), $guard);
    }

    public function testMonthlyFailureOrderMatchesChronologicalDaysAcrossHiddenPrefix(): void
    {
        $empty = static fn (string $date): NetForecastDay => new NetForecastDay($date, [], NetForecastQuantities::zero(), null, null, 'money_currency_missing', NetForecastRevenue::empty());
        $cases = [
            [[self::day('2026-08-31'), self::day('2026-09-01', currency: 'USD'), self::day('2026-09-02', price: null)], 'money_currency_mismatch', 'money_price_missing'],
            [[self::day('2026-08-31'), self::day('2026-09-01', price: null), self::day('2026-09-02', currency: 'USD')], 'money_price_missing', 'money_price_missing'],
            [[self::day('2026-08-31', price: null), self::day('2026-09-01', currency: 'USD'), self::day('2026-09-02')], 'money_price_missing', 'money_currency_mismatch'],
            [[$empty('2026-08-31'), self::day('2026-09-01', currency: 'USD'), self::day('2026-09-02', price: null)], 'money_price_missing', 'money_price_missing'],
            [[self::day('2026-08-31', numerator: '0'), self::day('2026-09-01', numerator: '0', currency: 'USD'), self::day('2026-09-02', price: null)], 'money_currency_mismatch', 'money_price_missing'],
            [[self::day('2026-08-31', numerator: '2', denominator: '1', price: (string) \PHP_INT_MAX), self::day('2026-09-01', currency: 'USD'), self::day('2026-09-02', price: null)], 'money_currency_mismatch', 'money_price_missing'],
            [[self::day('2026-08-31'), $empty('2026-09-01'), self::day('2026-09-02', currency: 'USD')], 'money_currency_mismatch', null],
        ];
        foreach ($cases as [$days, $cumulativeReason, $periodReason]) {
            $point = (new ProjectNetForecastSeriesAction())(self::batch($days), self::request('2026-09-01', '2026-09-02', 'month', '2026-08-31'), static function (): void {})->skus[0]->points[0];
            self::assertSame($cumulativeReason, $point->cumulative->moneyUnavailableReason);
            self::assertSame($periodReason, $point->period->moneyUnavailableReason);
            self::assertNotNull($point->period->quantities);
            self::assertNotNull($point->cumulative->quantities);
            self::assertNull($point->cumulative->expectedNetRevenue);
            self::assertNull($point->cumulative->unavailableReason);
        }
    }

    public function testGuardRefusesProjectionInsteadOfPublishingPartialSeries(): void
    {
        $this->expectException(\OverflowException::class);
        (new ProjectNetForecastSeriesAction())(self::batch([self::day('2026-08-01')]), self::request('2026-08-01', '2026-08-01'), static function (): void { throw new \OverflowException('shared budget'); });
    }

    private static function request(string $from, string $to, string $groupBy = 'day', ?string $accumulationFrom = null): NetForecastSeriesRequest
    {
        return new NetForecastSeriesRequest(['SKU'], new \DateTimeImmutable($from), new \DateTimeImmutable($to), null === $accumulationFrom ? null : new \DateTimeImmutable($accumulationFrom), $groupBy);
    }

    /** @param list<NetForecastDay> $days */
    private static function batch(array $days): NetForecastBatch
    {
        return new NetForecastBatch('company', 'account', null, null, [new NetForecastSku('SKU', $days, null, null, null, null)], null, null, null, null);
    }

    private static function day(string $date, string $numerator = '1', string $denominator = '3', ?string $price = '1', string $currency = 'RUB', ?string $reason = null): NetForecastDay
    {
        $q = null === $reason ? NetForecastQuantities::fromComponents('0', $numerator, '0', '1', $numerator, $denominator) : null;
        $revenue = null === $q ? NetForecastRevenue::unavailable($reason) : NetForecastRevenue::fromSource($price, $currency, $q);
        $moneyReason = $revenue->unavailableReason();
        try {
            $money = $revenue->toMoney();
        } catch (\OverflowException) {
            $money = null;
            $moneyReason = 'money_overflow';
        }

        return new NetForecastDay($date, [], $q, $reason, $money, $moneyReason, $revenue);
    }
}
