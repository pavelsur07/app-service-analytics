<?php

declare(strict_types=1);

namespace App\SalesPlanning\Infrastructure\Query;

final readonly class SalesPlanSkuRow
{
    public function __construct(
        public string $marketplaceSku,
        public int $totalQuantity,
        public int $revenueMinor,
    ) {
    }
}
