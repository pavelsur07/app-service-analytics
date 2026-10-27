<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Dashboard;

/**
 * Изменения к периоду сравнения (`DashboardChange`). Суммы и штуки —
 * относительные, в базисных пунктах; ДРР и выкуп — разница в базисных
 * пунктах. NULL — сравнивать не с чем.
 */
final readonly class DashboardChanges
{
    public function __construct(
        public ?int $ordersAmountBps,
        public ?int $ordersQuantityBps,
        public ?int $salesAmountBps,
        public ?int $salesQuantityBps,
        public ?int $returnsAmountBps,
        public ?int $returnsQuantityBps,
        public ?int $advertisingBps,
        public ?int $costPerOrderBps,
        public ?int $drrOrdersPointsBps,
        public ?int $costPerSaleBps,
        public ?int $drrSalesPointsBps,
        public ?int $deliveryLogisticsBps,
        public ?int $deliveryCountBps,
        public ?int $returnLogisticsBps,
        public ?int $returnLogisticsCountBps,
        public ?int $buyoutRatePointsBps,
        public ?int $buyoutQuantityBps,
        public ?int $buyoutOrderedQuantityBps,
        public ?int $profitBps,
    ) {
    }
}
