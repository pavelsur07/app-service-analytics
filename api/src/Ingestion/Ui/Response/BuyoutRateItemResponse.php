<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['marketplaceSku', 'offerId', 'name', 'orderedQuantity', 'resolvedQuantity', 'deliveredQuantity', 'actualBuyoutBaseQuantity', 'actualBuyoutRateBps', 'projectedBuyoutQuantity', 'projectedBuyoutRateBps', 't1RateBps', 't2RateBps', 'partialReturnRateBps', 'maturityStatus', 'resolutionRateBps'])]
final readonly class BuyoutRateItemResponse
{
    public function __construct(
        public string $marketplaceSku,
        public ?string $offerId,
        public ?string $name,
        public int $orderedQuantity,
        public int $resolvedQuantity,
        public int $deliveredQuantity,
        public int $actualBuyoutBaseQuantity,
        public ?int $actualBuyoutRateBps,
        public ?int $projectedBuyoutQuantity,
        public ?int $projectedBuyoutRateBps,
        public ?int $t1RateBps,
        public ?int $t2RateBps,
        public ?int $partialReturnRateBps,
        #[OA\Property(enum: ['mature', 'preliminary'])]
        public string $maturityStatus,
        public ?int $resolutionRateBps,
    ) {
    }
}
