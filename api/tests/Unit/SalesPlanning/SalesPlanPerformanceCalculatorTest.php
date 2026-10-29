<?php

declare(strict_types=1);

namespace App\Tests\Unit\SalesPlanning;

use App\Ingestion\Application\Facade\SalesCohortComponents;
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
        self::assertSame('net_forecast_not_available', $result->forecastUnavailableReason);
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

    private function facts(int $observed = 700): SalesCohortComponents
    {
        return new SalesCohortComponents(1000, 50, 950, $observed, Money::ofMinor(123, 'RUB'), 100, 800, 0, 0,
            '900', '1000', 'preliminary', 1000, 8750, null, 900, 9000, 0);
    }
}
