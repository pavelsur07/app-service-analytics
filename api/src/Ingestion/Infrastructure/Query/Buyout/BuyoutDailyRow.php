<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Buyout;

final readonly class BuyoutDailyRow
{
    public function __construct(
        public string $date,
        public ?int $actualBuyoutRateBps,
        public ?int $projectedBuyoutRateBps,
        public ?int $resolutionRateBps,
        public int $orderedQuantity,
        public int $resolvedQuantity,
        public ?int $projectedBuyoutQuantity,
        public string $maturityStatus,
        public ?int $inFlightRateBps,
        /** Выкуп только по известным исходам, без условия зрелости; наружу не отдаётся. */
        public ?int $knownBuyoutRateBps,
        public ?int $unestimatedRateBps,
    ) {
    }
}
