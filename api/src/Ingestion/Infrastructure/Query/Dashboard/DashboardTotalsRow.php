<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Dashboard;

/**
 * Итоги одного периода главной в одной валюте. Суммы расходов, возвратов
 * и логистики — модулем; отношения — NULL при нуле в знаменателе.
 */
final readonly class DashboardTotalsRow
{
    public function __construct(
        public bool $current,
        public string $currency,
        public int $ordersQuantity,
        public int $ordersAmountMinor,
        public int $salesQuantity,
        public int $salesAmountMinor,
        public int $returnsQuantity,
        public int $returnsAmountMinor,
        public int $advertisingMinor,
        public ?int $costPerOrderMinor,
        public ?int $drrOrdersBps,
        public ?int $costPerSaleMinor,
        public ?int $drrSalesBps,
        public int $deliveryLogisticsMinor,
        public int $deliveryCount,
        public int $returnLogisticsMinor,
        public int $returnLogisticsCount,
    ) {
    }
}
