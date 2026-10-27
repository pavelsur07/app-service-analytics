<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Dashboard;

/** Ограниченный агрегат начислений и себестоимости; деньги со знаками источника. */
final readonly class DashboardProfitDailyRow
{
    public function __construct(
        public string $date,
        public string $currency,
        public int $accrualsTotalMinor,
        public int $costTotalMinor,
        public int $quantityWithoutCost,
        public int $costCurrencyMismatchQuantity,
        public ?string $costCorrectedAt,
    ) {
    }
}
