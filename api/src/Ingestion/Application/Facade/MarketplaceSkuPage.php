<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

final readonly class MarketplaceSkuPage
{
    /** @param list<MarketplaceSku> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public string $sourceVersion,
    ) {
    }
}
