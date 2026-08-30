<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['marketplaceSku', 'series'])]
final readonly class BuyoutDailyResponse
{
    /** @param list<BuyoutDailyPointResponse> $series */
    public function __construct(
        public string $marketplaceSku,
        public array $series,
    ) {
    }
}
