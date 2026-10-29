<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

use App\SalesPlanning\Application\SalesPlanPerformanceCohort;
use OpenApi\Attributes as OA;

final readonly class PerformanceCohortResponse
{
    public function __construct(
        public int $rawOrderedQuantity,
        public int $preHandoverCancelledQuantity,
        public int $effectiveOrderedQuantity,
        public int $observedNetSalesQuantity,
        public ?PerformanceMoneyResponse $observedRevenue,
        public int $inFlightQuantity,
        public int $buyoutResolvedQuantity,
        public int $unestimatedQuantity,
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
        #[OA\Property(pattern: '^[0-9]+\.[0-9]{3}$', nullable: true)] public ?string $expectedNetSalesQuantity,
        public ?string $netForecastUnavailableReason,
        public ?PerformanceNetMoneyResponse $expectedNetRevenue,
        public ?string $netForecastMoneyUnavailableReason,
        public PerformanceNetQualityResponse $netForecastQuality,
    ) {
    }

    public static function of(?SalesPlanPerformanceCohort $c): ?self
    {
        return null === $c ? null : new self(
            $c->rawOrderedQuantity,
            $c->preHandoverCancelledQuantity,
            $c->effectiveOrderedQuantity,
            $c->observedNetSalesQuantity,
            PerformanceMoneyResponse::of($c->observedRevenue),
            $c->inFlightQuantity,
            $c->buyoutResolvedQuantity,
            $c->unestimatedQuantity,
            $c->unknownOutcomeQuantity,
            $c->knownProjectedBuyoutQuantity,
            $c->knownProjectedEligibleQuantity,
            $c->maturityStatus,
            $c->inFlightRateBps,
            $c->knownBuyoutRateBps,
            $c->actualBuyoutRateBps,
            $c->currentProjectedBuyoutQuantity,
            $c->currentProjectedBuyoutRateBps,
            $c->unestimatedRateBps,
            $c->expectedNetSalesQuantity,
            $c->netForecastUnavailableReason,
            PerformanceNetMoneyResponse::of($c->expectedNetRevenue),
            $c->netForecastMoneyUnavailableReason,
            PerformanceNetQualityResponse::of($c->netForecastQuality),
        );
    }
}
