<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Buyout;

final readonly class BuyoutForecastSummaryRow
{
    public function __construct(
        public int $orderedQuantity,
        public int $resolvedQuantity,
        public ?int $projectedBuyoutQuantity,
        public ?int $projectedBuyoutRateBps,
        public ?int $resolutionRateBps,
        public ?int $unestimatedRateBps,
    ) {
    }
}
