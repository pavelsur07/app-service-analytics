<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\Shared\Domain\ValueObject\Money;

final readonly class SalesPlanPerformanceValues
{
    public function __construct(
        public int $planQuantity,
        public Money $planRevenueRrp,
        public int $calendarDays,
        public string $planSpeed,
        public ?SalesPlanPerformanceCohort $cohort,
        public ?int $actualGoalQuantity,
        public ?int $quantityDeviation,
        public ?int $attainmentBps,
        /** Pace of the selected goal metric, units per calendar day. */
        public ?string $actualSpeed,
        /** Observed retained sales D per calendar day, independent of the selected goal. */
        public ?string $observedSalesSpeed,
        public ?Money $revenueDeviation,
        public ?string $expectedGoalQuantity = null,
        public ?int $expectedAttainmentBps = null,
        public ?string $expectedSpeed = null,
        public string $forecastUnavailableReason = 'net_forecast_not_available',
    ) {
    }
}
