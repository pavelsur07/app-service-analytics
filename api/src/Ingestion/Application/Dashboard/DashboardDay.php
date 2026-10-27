<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Dashboard;

/** Суммы одного дня — точка спарклайнов. */
final readonly class DashboardDay
{
    public function __construct(
        public string $date,
        public int $ordersAmountMinor,
        public int $salesAmountMinor,
        public int $returnsAmountMinor,
        public int $advertisingMinor,
        public int $deliveryLogisticsMinor,
        public int $returnLogisticsMinor,
        public ?int $profitMinor,
    ) {
    }
}
