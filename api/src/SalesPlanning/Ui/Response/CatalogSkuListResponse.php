<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

final readonly class CatalogSkuListResponse
{
    /**
     * @param list<CatalogSkuResponse> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
