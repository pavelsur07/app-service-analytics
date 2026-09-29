<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['items', 'nextCursor'])]
final readonly class SkuForecastFactSkuSearchResponse
{
    /** @param list<SkuForecastFactSkuResponse> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
