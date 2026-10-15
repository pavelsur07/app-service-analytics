<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Dashboard;

/** Суммы одного дня для спарклайнов главной. */
final readonly class DashboardDailyRow
{
    public function __construct(
        public string $date,
        public string $currency,
        public int $ordersAmountMinor,
        public int $salesAmountMinor,
        public int $returnsAmountMinor,
        public int $advertisingMinor,
        public int $deliveryLogisticsMinor,
        public int $returnLogisticsMinor,
    ) {
    }
}
