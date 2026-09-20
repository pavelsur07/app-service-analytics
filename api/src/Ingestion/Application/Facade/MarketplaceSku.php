<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

final readonly class MarketplaceSku
{
    public function __construct(
        public string $marketplaceSku,
        public ?string $offerId,
        public ?string $name,
    ) {
    }
}
