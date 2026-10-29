<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

use App\Shared\Domain\ValueObject\Money;

/** Only available with complete orders coverage; returns remain unassessed. */
final readonly class SalesCohortComponents
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
        public ?string $expectedNetSalesQuantity = null,
        public ?string $netForecastUnavailableReason = 'validation_pending',
        public ?Money $expectedNetRevenue = null,
        public ?string $netForecastMoneyUnavailableReason = 'validation_pending',
        public SalesCohortNetQuality $netForecastQuality = new SalesCohortNetQuality(),
        public ?SalesCohortGoalForecast $goalForecast = null,
    ) {
    }

    /** Bounded in-memory fallback; factual and gross components remain unchanged. */
    public function withoutForecast(): self
    {
        return new self(
            $this->rawOrderedQuantity, $this->preHandoverCancelledQuantity, $this->effectiveOrderedQuantity,
            $this->observedNetSalesQuantity, $this->observedRevenue, $this->inFlightQuantity,
            $this->buyoutResolvedQuantity, $this->unestimatedQuantity, $this->unknownOutcomeQuantity,
            $this->knownProjectedBuyoutQuantity, $this->knownProjectedEligibleQuantity, $this->maturityStatus,
            $this->inFlightRateBps, $this->knownBuyoutRateBps, $this->actualBuyoutRateBps,
            $this->currentProjectedBuyoutQuantity, $this->currentProjectedBuyoutRateBps, $this->unestimatedRateBps,
            null, 'input_budget_exceeded', null, 'input_budget_exceeded',
            new SalesCohortNetQuality('input_budget_exceeded', 'input_budget_exceeded', [], [],
                $this->netForecastQuality->validationStatus, $this->netForecastQuality->assessmentStatus),
        );
    }
}
