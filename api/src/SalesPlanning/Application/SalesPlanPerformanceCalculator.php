<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\Ingestion\Application\Facade\SalesCohortComponents;
use App\SalesPlanning\Domain\SalesPlanMetric;
use App\Shared\Domain\ValueObject\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Relationships only: quantities/revenue have already been aggregated by SQL. */
final readonly class SalesPlanPerformanceCalculator
{
    public function calculate(int $quantity, Money $revenue, int $days, SalesPlanMetric $metric, ?SalesCohortComponents $cohort): SalesPlanPerformanceValues
    {
        if ($days < 1 || $quantity < 0) {
            throw new \InvalidArgumentException('Invalid performance denominator.');
        }
        $actual = null === $cohort ? null : match ($metric) {
            SalesPlanMetric::RetainedSales => $cohort->observedNetSalesQuantity,
            SalesPlanMetric::OrdersExcludingPreHandoverCancellations => $cohort->effectiveOrderedQuantity,
        };
        $observedRevenue = $cohort?->observedRevenue;

        return new SalesPlanPerformanceValues(
            $quantity, $revenue, $days, self::speed($quantity, $days), SalesPlanPerformanceCohort::of($cohort),
            $actual, null === $actual ? null : $actual - $quantity,
            null === $actual || 0 === $quantity ? null : BigDecimal::of($actual)->multipliedBy(10000)->dividedBy($quantity, 0, RoundingMode::HalfUp)->toInt(),
            null === $actual ? null : self::speed($actual, $days),
            null === $cohort ? null : self::speed($cohort->observedNetSalesQuantity, $days),
            null === $observedRevenue || $observedRevenue->currency() !== $revenue->currency() ? null : $observedRevenue->minus($revenue),
        );
    }

    private static function speed(int $quantity, int $days): string
    {
        return (string) BigDecimal::of($quantity)->dividedBy($days, 3, RoundingMode::HalfUp);
    }
}
