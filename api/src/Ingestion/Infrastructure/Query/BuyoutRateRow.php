<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

/** Quantity-weighted aggregate одного marketplace SKU. */
final readonly class BuyoutRateRow
{
    public function __construct(
        public string $marketplaceSku,
        public ?string $offerId,
        public ?string $name,
        public int $orderedQuantity,
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
