<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\Buyout;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['orderedQuantity', 'resolvedQuantity', 'projectedBuyoutQuantity', 'projectedBuyoutRateBps', 'resolutionRateBps', 'unestimatedRateBps'])]
final readonly class BuyoutRateSummaryResponse
{
    public function __construct(
        public int $orderedQuantity,
        public int $resolvedQuantity,
        public ?int $projectedBuyoutQuantity,
        public ?int $projectedBuyoutRateBps,
        public ?int $resolutionRateBps,
        #[OA\Property(description: 'Доля заказанного количества без оценки прогноза (ADR-031), bps; прогноз построен по остальным')]
        public ?int $unestimatedRateBps,
    ) {
    }
}
