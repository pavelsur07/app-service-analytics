<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['date', 'actualBuyoutRateBps', 'projectedBuyoutRateBps', 'resolutionRateBps', 'orderedQuantity', 'resolvedQuantity', 'projectedBuyoutQuantity'])]
final readonly class BuyoutDailyPointResponse
{
    public function __construct(
        public string $date,
        public ?int $actualBuyoutRateBps,
        public ?int $projectedBuyoutRateBps,
        public ?int $resolutionRateBps,
        public int $orderedQuantity,
        public int $resolvedQuantity,
        public ?int $projectedBuyoutQuantity,
    ) {
    }
}
