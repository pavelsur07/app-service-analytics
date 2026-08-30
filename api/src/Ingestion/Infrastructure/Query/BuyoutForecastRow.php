<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

/** Накопительный прогноз одного SKU за выбранный cohort range. */
final readonly class BuyoutForecastRow
{
    public function __construct(
        public string $marketplaceSku,
        public int $orderedQuantity,
        public int $resolvedQuantity,
        public ?int $projectedBuyoutQuantity,
        public ?int $projectedBuyoutRateBps,
        public int $resolutionRateBps,
    ) {
    }
}
