<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Listings;

final readonly class AccountCatalogSkuRow
{
    public function __construct(
        public string $marketplaceSku,
        public ?string $offerId,
        public ?string $name,
    ) {
    }
}
