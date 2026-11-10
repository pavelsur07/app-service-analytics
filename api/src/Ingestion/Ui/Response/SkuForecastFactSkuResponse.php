<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['marketplaceSku', 'name', 'offerId', 'photoUrl'])]
final readonly class SkuForecastFactSkuResponse
{
    public function __construct(
        public string $marketplaceSku,
        public ?string $name,
        public ?string $offerId,
        public ?string $photoUrl,
    ) {
    }
}
