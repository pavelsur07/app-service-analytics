<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\Ingestion\Application\Facade\SalesCohortComponents;
use App\Shared\Domain\ValueObject\Money;

/** Only available with complete orders coverage; returns remain unassessed. */
final readonly class SalesPlanPerformanceCohort
{
    public function __construct(
        public int $rawOrderedQuantity,
        public int $preHandoverCancelledQuantity,
        public int $effectiveOrderedQuantity,
        public int $observedNetSalesQuantity,
        public ?Money $observedRevenue,
        public int $inFlightQuantity,
        public int $buyoutResolvedQuantity,
        public int $unestimatedQuantity,
        /** NULL outcome and not forecast eligible; known active without training is separate. */
        public int $unknownOutcomeQuantity,
        public string $knownProjectedBuyoutQuantity,
        public string $knownProjectedEligibleQuantity,
        public string $maturityStatus,
        public ?int $inFlightRateBps,
        public ?int $knownBuyoutRateBps,
        public ?int $actualBuyoutRateBps,
        public ?int $currentProjectedBuyoutQuantity,
        public ?int $currentProjectedBuyoutRateBps,
        public ?int $unestimatedRateBps,
        public ?string $expectedNetSalesQuantity,
        public ?string $netForecastUnavailableReason,
        public ?Money $expectedNetRevenue,
        public ?string $netForecastMoneyUnavailableReason,
        public SalesPlanPerformanceNetQuality $netForecastQuality,
    ) {
    }

    public static function of(?SalesCohortComponents $value): ?self
    {
        return null === $value ? null : new self(
            $value->rawOrderedQuantity,
            $value->preHandoverCancelledQuantity,
            $value->effectiveOrderedQuantity,
            $value->observedNetSalesQuantity,
            $value->observedRevenue,
            $value->inFlightQuantity,
            $value->buyoutResolvedQuantity,
            $value->unestimatedQuantity,
            $value->unknownOutcomeQuantity,
            $value->knownProjectedBuyoutQuantity,
            $value->knownProjectedEligibleQuantity,
            $value->maturityStatus,
            $value->inFlightRateBps,
            $value->knownBuyoutRateBps,
            $value->actualBuyoutRateBps,
            $value->currentProjectedBuyoutQuantity,
            $value->currentProjectedBuyoutRateBps,
            $value->unestimatedRateBps,
            $value->expectedNetSalesQuantity,
            $value->netForecastUnavailableReason,
            $value->expectedNetRevenue,
            $value->netForecastMoneyUnavailableReason,
            SalesPlanPerformanceNetQuality::of($value->netForecastQuality),
        );
    }
}
