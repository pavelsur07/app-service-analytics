<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\SalesPlanning\Infrastructure\Query\SalesPlanRow;

final readonly class SalesPlansPage
{
    /**
     * @param list<SalesPlanRow> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
