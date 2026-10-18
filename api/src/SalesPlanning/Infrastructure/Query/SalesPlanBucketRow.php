<?php

declare(strict_types=1);

namespace App\SalesPlanning\Infrastructure\Query;

/**
 * Корзина периода одного SKU: первый и последний день корзины внутри
 * периода плана, сумма штук и выручки за эти дни.
 */
final readonly class SalesPlanBucketRow
{
    public function __construct(
        public string $marketplaceSku,
        public string $dateFrom,
        public string $dateTo,
        public int $quantity,
        public int $revenueMinor,
    ) {
    }
}
