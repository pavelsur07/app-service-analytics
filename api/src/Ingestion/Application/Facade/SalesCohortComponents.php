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
    ) {
    }
}
