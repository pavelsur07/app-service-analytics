<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['summary', 'items', 'nextCursor'])]
final readonly class BuyoutRateListResponse
{
    /** @param list<BuyoutRateItemResponse> $items */
    public function __construct(
        public BuyoutRateSummaryResponse $summary,
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
