<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

use App\SalesPlanning\Application\SalesPlanPerformanceValues;

final readonly class PerformanceValuesResponse
{
    public function __construct(
        public int $planQuantity,
        public PerformanceMoneyResponse $planRevenueRrp,
        public int $calendarDays,
        public string $planSpeed,
        public ?PerformanceCohortResponse $cohort,
        public ?int $actualGoalQuantity,
        public ?int $quantityDeviation,
        public ?int $attainmentBps,
        /** Pace of the selected goal metric, units per calendar day. */
        public ?string $actualSpeed,
        /** Observed retained sales D per calendar day, three decimals, HalfUp. */
        public ?string $observedSalesSpeed,
        public ?PerformanceMoneyResponse $revenueDeviation,
        public ?string $expectedGoalQuantity,
        public ?int $expectedAttainmentBps,
        public ?string $expectedSpeed,
        public string $forecastUnavailableReason,
    ) {
    }

    public static function of(SalesPlanPerformanceValues $v): self
    {
        return new self(
            $v->planQuantity,
            new PerformanceMoneyResponse($v->planRevenueRrp->minorAmount(), $v->planRevenueRrp->currency()),
            $v->calendarDays,
            $v->planSpeed,
            PerformanceCohortResponse::of($v->cohort),
            $v->actualGoalQuantity,
            $v->quantityDeviation,
            $v->attainmentBps,
            $v->actualSpeed,
            $v->observedSalesSpeed,
            PerformanceMoneyResponse::of($v->revenueDeviation),
            $v->expectedGoalQuantity,
            $v->expectedAttainmentBps,
            $v->expectedSpeed,
            $v->forecastUnavailableReason,
        );
    }
}
