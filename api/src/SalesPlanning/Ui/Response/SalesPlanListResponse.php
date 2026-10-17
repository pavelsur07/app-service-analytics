<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

final readonly class SalesPlanListResponse
{
    /**
     * @param list<SalesPlanResponse> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
