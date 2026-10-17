<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

final readonly class CatalogSkuResponse
{
    public function __construct(
        public string $marketplaceSku,
        public ?string $offerId,
        public ?string $name,
    ) {
    }
}
