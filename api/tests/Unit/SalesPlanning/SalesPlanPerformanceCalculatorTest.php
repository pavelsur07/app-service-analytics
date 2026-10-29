<?php

declare(strict_types=1);

namespace App\Tests\Unit\SalesPlanning;

use App\Ingestion\Application\Facade\SalesCohortComponents;
use App\Ingestion\Application\Facade\SalesCohortGoalForecast;
use App\Ingestion\Application\Facade\SalesCohortNetQuality;
use App\SalesPlanning\Application\SalesPlanPerformanceCalculator;
use App\SalesPlanning\Domain\SalesPlanMetric;
use App\SalesPlanning\Ui\Response\PerformanceCohortResponse;
use App\Shared\Domain\ValueObject\Money;
use PHPUnit\Framework\TestCase;

final class SalesPlanPerformanceCalculatorTest extends TestCase
{
    public function testObservedRetainedGoalIsSeventyPercentAndBuyoutDoesNotInventNetForecast(): void
    {
        $facts = $this->facts();
        $result = (new SalesPlanPerformanceCalculator())->calculate(1000, Money::ofMinor(123456789, 'RUB'), 3, SalesPlanMetric::RetainedSales, $facts);
        self::assertSame(700, $result->actualGoalQuantity);
        self::assertSame(7000, $result->attainmentBps);
        self::assertSame(-300, $result->quantityDeviation);
        self::assertSame('233.333', $result->actualSpeed);
        self::assertSame('333.333', $result->planSpeed);
        self::assertSame(-123456666, $result->revenueDeviation?->minorAmount());
        self::assertSame(900, $result->cohort?->currentProjectedBuyoutQuantity);
        self::assertNull($result->expectedGoalQuantity);
        self::assertNull($result->expectedAttainmentBps);
        self::assertNull($result->expectedSpeed);
        self::assertSame('validation_pending', $result->forecastUnavailableReason);
    }

    public function testOrderMetricZeroTargetPartialAndMissingMoneyRemainDistinct(): void
    {
        $calculator = new SalesPlanPerformanceCalculator();
        $value = $calculator->calculate(0, Money::ofMinor(0, 'USD'), 2, SalesPlanMetric::OrdersExcludingPreHandoverCancellations, $this->facts());
        self::assertSame(950, $value->actualGoalQuantity);
        self::assertSame(700, $value->cohort?->observedNetSalesQuantity);
        self::assertNull($value->attainmentBps);
        self::assertSame(950, $value->quantityDeviation);
        self::assertSame('475.000', $value->actualSpeed);
        self::assertSame('350.000', $value->observedSalesSpeed);
        self::assertNull($value->revenueDeviation);
        $partial = $calculator->calculate(1000, Money::ofMinor(123, 'RUB'), 2, SalesPlanMetric::RetainedSales, null);
        self::assertSame(1000, $partial->planQuantity);
        self::assertNull($partial->actualGoalQuantity);
        self::assertNull($partial->quantityDeviation);
        self::assertNull($partial->actualSpeed);
        self::assertNull($partial->observedSalesSpeed);
        self::assertNull($partial->attainmentBps);
    }

    public function testBpsAndCalendarSpeedUseHalfUpAtTheBoundary(): void
    {
        $value = (new SalesPlanPerformanceCalculator())->calculate(32, Money::ofMinor(123, 'RUB'), 16, SalesPlanMetric::RetainedSales, $this->facts(1));
        self::assertSame(313, $value->attainmentBps);
        self::assertSame('0.063', $value->actualSpeed);
    }

    public function testAvailableNetIsCopiedThroughOwnCohortWithoutComputingB14Fields(): void
    {
        $quality = new SalesCohortNetQuality(null, 'money_price_missing', ['sku'], ['insufficient_sample'], 'release_approved', 'assessed');
        $facts = new SalesCohortComponents(100, 0, 100, 70, Money::ofMinor(7000, 'RUB'), 50, 70, 0, 0,
            '95', '120', 'preliminary', 4000, 9000, null, 95, 9500, 0,
            '90.000', null, null, 'money_price_missing', $quality);
        $values = (new SalesPlanPerformanceCalculator())->calculate(100, Money::ofMinor(10000, 'RUB'), 1, SalesPlanMetric::RetainedSales, $facts);
        $response = PerformanceCohortResponse::of($values->cohort);
        self::assertNotNull($response);
        self::assertSame('90.000', $response->expectedNetSalesQuantity);
        self::assertNull($response->netForecastUnavailableReason);
        self::assertNull($response->expectedNetRevenue);
        self::assertSame('money_price_missing', $response->netForecastMoneyUnavailableReason);
        self::assertSame(['sku'], $response->netForecastQuality->trainingScopes);
        self::assertSame(['insufficient_sample'], $response->netForecastQuality->componentReasons);
        self::assertSame('assessed', $response->netForecastQuality->assessmentStatus);
        self::assertSame('release_approved', $response->netForecastQuality->validationStatus);
        self::assertNull($values->expectedGoalQuantity);
        self::assertNull($values->expectedAttainmentBps);
        self::assertNull($values->expectedSpeed);
    }

    public function testBothMetricsUseFullExactGoalAndIndependentFourRatios(): void
    {
        $calc = new SalesPlanPerformanceCalculator();
        $forecast = new SalesCohortGoalForecast('900', '1', null, '50', '1', null);
        foreach (SalesPlanMetric::cases() as $metric) {
            $result = $calc->calculate(1000, Money::ofMinor(0, 'RUB'), 3, $metric, $this->facts(goal: $forecast), forecastGuard: static function (int $bytes = 0): void {});
            self::assertSame('900.000', $result->expectedGoalQuantity);
            self::assertSame(9000, $result->expectedAttainmentBps);
            self::assertSame('-100.000', $result->expectedQuantityDeviation);
            self::assertSame('300.000', $result->expectedSpeed);
            self::assertNull($result->forecastUnavailableReason);
            self::assertSame(SalesPlanMetric::RetainedSales === $metric ? 7000 : 9500, $result->attainmentBps);
        }
        foreach ([['1', '3', 1, 1, '0.333', 3333, '-0.667', '0.333'], ['1', '16', 1, 1, '0.063', 625, '-0.938', '0.063'], ['1', '1', 32, 16, '1.000', 313, '-31.000', '0.063'], ['0', '1', 1, 1, '0.000', 0, '-1.000', '0.000'], ['1', '3', 0, 1, '0.333', null, '0.333', '0.333'], ['9999', '10000', 1, 1, '1.000', 9999, '0.000', '1.000']] as [$n, $d, $plan, $days, $q, $bps, $dev, $speed]) {
            $result = $calc->calculate($plan, Money::ofMinor(0, 'RUB'), $days, SalesPlanMetric::RetainedSales, $this->facts(goal: new SalesCohortGoalForecast($n, $d, null, null, null, 'gross_probability_unavailable')), forecastGuard: static function (int $bytes = 0): void {});
            self::assertSame([$q, $bps, $dev, $speed, null], [$result->expectedGoalQuantity, $result->expectedAttainmentBps, $result->expectedQuantityDeviation, $result->expectedSpeed, $result->forecastUnavailableReason]);
        }
    }

    public function testJavascriptIntegerBoundIsCheckedBeforeNativeCastAndKeepsFacts(): void
    {
        foreach (['9007199254740991', '9007199254740992', '9007199254740993', '92233720368547758080000'] as $n) {
            $result = (new SalesPlanPerformanceCalculator())->calculate(1, Money::ofMinor(0, 'RUB'), 1, SalesPlanMetric::RetainedSales, $this->facts(goal: new SalesCohortGoalForecast($n, '10000', null, '0', '1', null)), forecastGuard: static function (int $bytes = 0): void {});
            self::assertSame(700, $result->actualGoalQuantity);
            if ('9007199254740991' === $n) {
                self::assertSame(9007199254740991, $result->expectedAttainmentBps);
            } else {
                self::assertSame([null, null, null, null, 'goal_attainment_overflow'], [$result->expectedGoalQuantity, $result->expectedAttainmentBps, $result->expectedQuantityDeviation, $result->expectedSpeed, $result->forecastUnavailableReason]);
            }
        }
    }

    public function testGoalRequiresOriginalBudgetAndHonoursIndependentUnavailableReasons(): void
    {
        $calc = new SalesPlanPerformanceCalculator();
        $facts = $this->facts(goal: new SalesCohortGoalForecast(null, null, 'return_risk_unavailable', '0', '1', null));
        $orders = $calc->calculate(1000, Money::ofMinor(0, 'RUB'), 1, SalesPlanMetric::OrdersExcludingPreHandoverCancellations, $facts, forecastGuard: static function (int $bytes = 0): void {});
        self::assertSame('950.000', $orders->expectedGoalQuantity);
        self::assertSame('input_budget_exceeded', $calc->calculate(1000, Money::ofMinor(0, 'RUB'), 1, SalesPlanMetric::OrdersExcludingPreHandoverCancellations, $facts)->forecastUnavailableReason);
        self::assertSame('return_risk_unavailable', $calc->calculate(1000, Money::ofMinor(0, 'RUB'), 1, SalesPlanMetric::RetainedSales, $facts, forecastGuard: static function (int $bytes = 0): void {})->forecastUnavailableReason);
        $bad = $this->facts(goal: new SalesCohortGoalForecast('1', '1', null, '951', '1', null));
        self::assertSame('goal_component_mismatch', $calc->calculate(1000, Money::ofMinor(0, 'RUB'), 1, SalesPlanMetric::OrdersExcludingPreHandoverCancellations, $bad, forecastGuard: static function (int $bytes = 0): void {})->forecastUnavailableReason);
        self::assertSame('validation_pending', $calc->calculate(1, Money::ofMinor(0, 'RUB'), 1, SalesPlanMetric::RetainedSales, null, 'validation_pending')->forecastUnavailableReason);
    }

    public function testUnavailableOccurrencesChargeTheSharedOutputBeforeReturning(): void
    {
        foreach ([null, $this->facts()] as $cohort) {
            $used = 0;
            $calculator = new SalesPlanPerformanceCalculator();
            $guard = static function (int $bytes = 0) use (&$used): void { $used += $bytes; };
            $value = $calculator->calculate(1, Money::ofMinor(0, 'RUB'), 1, SalesPlanMetric::RetainedSales, $cohort, 'orders_coverage_unavailable', $guard);
            $serialized = json_encode(['expectedGoalQuantity' => null, 'expectedAttainmentBps' => null,
                'expectedQuantityDeviation' => null, 'expectedSpeed' => null, 'forecastUnavailableReason' => $value->forecastUnavailableReason], \JSON_THROW_ON_ERROR);
            self::assertGreaterThanOrEqual(\strlen($serialized), $used);
            $remaining = $used - 1;
            try {
                $calculator->calculate(1, Money::ofMinor(0, 'RUB'), 1, SalesPlanMetric::RetainedSales, $cohort, 'orders_coverage_unavailable', static function (int $bytes = 0) use ($remaining): void {
                    if ($bytes > $remaining) {
                        throw new \OverflowException('Original output exhausted.');
                    }
                });
                self::fail('Unavailable occurrence must obey the original remaining output.');
            } catch (\OverflowException $exception) {
                self::assertSame('Original output exhausted.', $exception->getMessage());
            }
        }
    }

    private function facts(int $observed = 700, ?SalesCohortGoalForecast $goal = null): SalesCohortComponents
    {
        return new SalesCohortComponents(1000, 50, 950, $observed, Money::ofMinor(123, 'RUB'), 100, 800, 0, 0,
            '900', '1000', 'preliminary', 1000, 8750, null, 900, 9000, 0, goalForecast: $goal);
    }
}
