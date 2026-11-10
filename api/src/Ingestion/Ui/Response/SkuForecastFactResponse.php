<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['marketplaceSku', 'month', 'currency', 'days', 'name', 'offerId', 'photoUrl'])]
final readonly class SkuForecastFactResponse
{
    /** @param list<SkuForecastFactDayResponse> $days */
    public function __construct(
        public string $marketplaceSku,
        public string $month,
        #[OA\Property(enum: ['RUB'])]
        public string $currency,
        public array $days,
        public ?string $name,
        public ?string $offerId,
        public ?string $photoUrl,
    ) {
    }
}
