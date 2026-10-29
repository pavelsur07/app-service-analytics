<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\Ingestion\Application\Facade\SalesCohortComponents;
use App\SalesPlanning\Domain\SalesPlanMetric;
use App\Shared\Domain\ValueObject\Money;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/** Relationships only: quantities/revenue have already been aggregated by SQL. */
final readonly class SalesPlanPerformanceCalculator
{
    /** @param \Closure(int=): void|null $forecastGuard */
    public function calculate(int $quantity, Money $revenue, int $days, SalesPlanMetric $metric, ?SalesCohortComponents $cohort, string $missingForecastReason = 'orders_coverage_unavailable', ?\Closure $forecastGuard = null): SalesPlanPerformanceValues
    {
        if ($days < 1 || $quantity < 0) {
            throw new \InvalidArgumentException('Invalid performance denominator.');
        }
        $actual = null === $cohort ? null : match ($metric) {
            SalesPlanMetric::RetainedSales => $cohort->observedNetSalesQuantity,
            SalesPlanMetric::OrdersExcludingPreHandoverCancellations => $cohort->effectiveOrderedQuantity,
        };
        $observedRevenue = $cohort?->observedRevenue;
        [$expected, $bps, $deviation, $speed, $reason] = $this->goal($quantity, $days, $metric, $cohort, $missingForecastReason, $forecastGuard);

        return new SalesPlanPerformanceValues(
            $quantity, $revenue, $days, self::speed($quantity, $days), SalesPlanPerformanceCohort::of($cohort),
            $actual, null === $actual ? null : $actual - $quantity,
            null === $actual || 0 === $quantity ? null : BigDecimal::of($actual)->multipliedBy(10000)->dividedBy($quantity, 0, RoundingMode::HalfUp)->toInt(),
            null === $actual ? null : self::speed($actual, $days),
            null === $cohort ? null : self::speed($cohort->observedNetSalesQuantity, $days),
            null === $observedRevenue || $observedRevenue->currency() !== $revenue->currency() ? null : $observedRevenue->minus($revenue),
            $expected, $bps, $speed, $reason, $deviation,
        );
    }

    /** @param \Closure(int=): void|null $guard
     * @return array{?string, ?int, ?string, ?string, ?string} */
    private function goal(int $plan, int $days, SalesPlanMetric $metric, ?SalesCohortComponents $cohort, string $missingReason, ?\Closure $guard): array
    {
        if (null === $cohort) {
            $guard?->__invoke(160 + 6 * \strlen($missingReason));

            return [null, null, null, null, $missingReason];
        }
        $forecast = $cohort->goalForecast;
        if (null === $forecast) {
            $reason = $cohort->netForecastUnavailableReason ?? 'net_forecast_not_available';
            $guard?->__invoke(160 + 6 * \strlen($reason));

            return [null, null, null, null, $reason];
        }
        if (null === $guard) {
            return [null, null, null, null, 'input_budget_exceeded'];
        }
        $guard();
        $retained = SalesPlanMetric::RetainedSales === $metric;
        $reason = $retained ? $forecast->retainedUnavailableReason : $forecast->futureT1UnavailableReason;
        if (null !== $reason) {
            $guard(160 + 6 * \strlen($reason));

            return [null, null, null, null, $reason];
        }
        $numerator = $retained ? $forecast->retainedNumerator : $forecast->futureT1Numerator;
        $denominator = $retained ? $forecast->retainedDenominator : $forecast->futureT1Denominator;
        \assert(null !== $numerator && null !== $denominator);
        $n = BigInteger::of($numerator);
        $d = BigInteger::of($denominator);
        if (!$retained) {
            $effective = $d->multipliedBy($cohort->effectiveOrderedQuantity);
            self::precision($effective, $guard);
            $n = $effective->minus($n);
            self::precision($n, $guard);
            if ($n->isNegative()) {
                $guard(160 + 6 * \strlen('goal_component_mismatch'));

                return [null, null, null, null, 'goal_component_mismatch'];
            }
        }
        $planDenominator = $d->multipliedBy($plan);
        self::precision($planDenominator, $guard);
        $bps = null;
        if (0 !== $plan) {
            $scaled = $n->multipliedBy(10000);
            self::precision($scaled, $guard);
            $integer = $scaled->toBigDecimal()->dividedBy($planDenominator, 0, RoundingMode::HalfUp)->toBigInteger();
            $guard();
            if ($integer->isNegative() || $integer->isGreaterThan(min(\PHP_INT_MAX, 9007199254740991))) {
                $guard(160 + 6 * \strlen('goal_attainment_overflow'));

                return [null, null, null, null, 'goal_attainment_overflow'];
            }
            $bps = $integer->toInt();
        }
        $difference = $n->minus($planDenominator);
        self::precision($difference, $guard);
        $dayDenominator = $d->multipliedBy($days);
        self::precision($dayDenominator, $guard);
        $quantity = (string) $n->toBigDecimal()->dividedBy($d, 3, RoundingMode::HalfUp);
        $guard();
        $deviation = (string) $difference->toBigDecimal()->dividedBy($d, 3, RoundingMode::HalfUp);
        $guard();
        $speed = (string) $n->toBigDecimal()->dividedBy($dayDenominator, 3, RoundingMode::HalfUp);
        if ('-0.000' === $deviation) {
            $deviation = '0.000';
        }
        $guard(\strlen(json_encode(['expectedGoalQuantity' => $quantity, 'expectedAttainmentBps' => $bps,
            'expectedQuantityDeviation' => $deviation, 'expectedSpeed' => $speed, 'forecastUnavailableReason' => null], \JSON_THROW_ON_ERROR)));

        return [$quantity, $bps, $deviation, $speed, null];
    }

    /** @param \Closure(int=): void $guard */
    private static function precision(BigInteger $value, \Closure $guard): void
    {
        $guard();
        if (\strlen(ltrim((string) $value, '-')) > 16384) {
            throw new \OverflowException('Goal intermediate precision budget exceeded.');
        }
    }

    private static function speed(int $quantity, int $days): string
    {
        return (string) BigDecimal::of($quantity)->dividedBy($days, 3, RoundingMode::HalfUp);
    }
}
