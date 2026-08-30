<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

final readonly class BuyoutDailyRow
{
    public function __construct(
        public string $date,
        public ?int $actualBuyoutRateBps,
        public ?int $projectedBuyoutRateBps,
        public int $resolutionRateBps,
        public int $orderedQuantity,
        public int $resolvedQuantity,
        public ?int $projectedBuyoutQuantity,
    ) {
    }
}
