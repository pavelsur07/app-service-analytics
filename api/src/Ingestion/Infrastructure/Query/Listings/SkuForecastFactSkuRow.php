<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Listings;

final readonly class SkuForecastFactSkuRow
{
    public function __construct(
        public string $marketplaceSku,
        public ?string $name,
        public ?string $offerId,
    ) {
    }
}
