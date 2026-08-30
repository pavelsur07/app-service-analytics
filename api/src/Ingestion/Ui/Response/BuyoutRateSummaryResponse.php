<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['orderedQuantity', 'resolvedQuantity', 'projectedBuyoutQuantity', 'projectedBuyoutRateBps', 'resolutionRateBps'])]
final readonly class BuyoutRateSummaryResponse
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
