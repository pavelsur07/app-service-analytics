<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

final readonly class BuyoutRateSummary
{
    public function __construct(
        public int $orderedQuantity,
        public int $resolvedQuantity,
        public ?int $projectedBuyoutQuantity,
        public ?int $projectedBuyoutRateBps,
        public ?int $resolutionRateBps,
    ) {
    }
}
