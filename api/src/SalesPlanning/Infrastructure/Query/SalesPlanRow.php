<?php

declare(strict_types=1);

namespace App\SalesPlanning\Infrastructure\Query;

/**
 * Шапка плана с итогами — строка списка и заголовок плана.
 * Выручка — сумма `штуки × цена` по дням, в минорных единицах валюты плана.
 */
final readonly class SalesPlanRow
{
    public function __construct(
        public string $id,
        public string $marketplaceAccountId,
        public string $name,
        public string $dateFrom,
        public string $dateTo,
        public string $currency,
        public string $status,
        public string $lineageId,
        public int $versionNumber,
        public int $version,
        public string $createdAt,
        public int $skuCount,
        public int $totalQuantity,
        public int $revenueMinor,
    ) {
    }
}
