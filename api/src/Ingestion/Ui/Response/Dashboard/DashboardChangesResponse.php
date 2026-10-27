<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\Dashboard;

use App\Ingestion\Application\Dashboard\DashboardChanges;

/**
 * Изменения к периоду сравнения, в базисных пунктах. `*Bps` — относительное
 * изменение (1250 = +12,5 %), `*PointsBps` — разница процентов
 * (150 = +1,5 п.п.). NULL — сравнивать не с чем: в периоде сравнения ноль
 * или один из периодов загружен не полностью (`dataComplete`).
 */
final readonly class DashboardChangesResponse
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
        /** Изменение прибыли; null при нулевой базе или неизвестной прибыли. */
        public ?int $profitBps,
    ) {
    }

    public static function from(DashboardChanges $changes): self
    {
        return new self(
            $changes->ordersAmountBps,
            $changes->ordersQuantityBps,
            $changes->salesAmountBps,
            $changes->salesQuantityBps,
            $changes->returnsAmountBps,
            $changes->returnsQuantityBps,
            $changes->advertisingBps,
            $changes->costPerOrderBps,
            $changes->drrOrdersPointsBps,
            $changes->costPerSaleBps,
            $changes->drrSalesPointsBps,
            $changes->deliveryLogisticsBps,
            $changes->deliveryCountBps,
            $changes->returnLogisticsBps,
            $changes->returnLogisticsCountBps,
            $changes->buyoutRatePointsBps,
            $changes->buyoutQuantityBps,
            $changes->buyoutOrderedQuantityBps,
            $changes->profitBps,
        );
    }
}
