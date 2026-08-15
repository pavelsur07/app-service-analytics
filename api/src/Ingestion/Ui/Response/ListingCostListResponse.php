<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response;

final readonly class ListingCostListResponse
{
    /**
     * @param list<ListingCostItemResponse> $items
     */
    public function __construct(
        public string $from,
        public string $to,
        public string $on,
        public array $items,
        /** Всего карточек у компании. */
        public int $listingCount,
        /** Из них с заданной себестоимостью на дату $on. */
        public int $pricedCount,
        public ?string $nextCursor,
    ) {
    }
}
