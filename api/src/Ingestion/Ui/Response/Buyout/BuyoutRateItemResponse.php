<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\Buyout;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['marketplaceSku', 'offerId', 'name', 'orderedQuantity', 'resolvedQuantity', 'deliveredQuantity', 'actualBuyoutBaseQuantity', 'actualBuyoutRateBps', 'projectedBuyoutQuantity', 'projectedBuyoutRateBps', 't1RateBps', 't2RateBps', 'partialReturnRateBps', 'maturityStatus', 'resolutionRateBps', 'unestimatedRateBps'])]
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
        #[OA\Property(description: 'Доля заказанного количества без оценки прогноза (ADR-031), bps; прогноз построен по остальным')]
        public ?int $unestimatedRateBps,
    ) {
    }
}
