<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

/** Application DTO одного SKU; не привязан к DBAL row или HTTP response. */
final readonly class BuyoutRateSku
{
    public function __construct(
        public string $marketplaceSku,
        public ?string $offerId,
        public ?string $name,
        public int $orderedQuantity,
        public int $resolvedQuantity,
        public ?int $projectedBuyoutQuantity,
        public ?int $projectedBuyoutRateBps,
        public int $t1Quantity,
        public int $deliveredQuantity,
        public int $t2Quantity,
        public int $partialReturnQuantity,
        public int $clientReturnQuantity,
        public int $unresolvedQuantity,
        public ?int $conversionRateBps,
        public ?int $actualBuyoutRateBps,
        public ?int $resolutionRateBps,
        public ?int $t1RateBps,
        public ?int $t2RateBps,
        public ?int $partialReturnRateBps,
        public string $maturityStatus,
    ) {
    }
}
