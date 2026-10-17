<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

/**
 * Ввод одного SKU за весь период плана: штуки и РРЦ за штуку
 * в минорных единицах (ADR-040 п. 7). Валюты здесь нет — она одна
 * на план (п. 6), и цену в другой валюте прислать нельзя по построению.
 */
final readonly class SkuPeriodInput
{
    public function __construct(
        public string $marketplaceSku,
        public int $totalQuantity,
        public int $priceMinor,
    ) {
    }
}
