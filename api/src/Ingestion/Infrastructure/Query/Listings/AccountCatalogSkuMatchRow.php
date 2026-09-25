<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Listings;

final readonly class AccountCatalogSkuMatchRow
{
    public function __construct(
        public bool $hasCatalog,
        public bool $matched,
    ) {
    }
}
