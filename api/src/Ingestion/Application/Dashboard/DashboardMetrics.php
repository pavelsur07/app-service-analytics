<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Dashboard;

/**
 * Показатели главной за один период. Суммы — минорные единицы валюты
 * сводки; расходы, возвраты и логистика — модулем. Отношения и выкуп —
 * NULL, когда считать не из чего.
 */
final readonly class DashboardMetrics
{
    public function __construct(
        public int $ordersAmountMinor,
        public int $ordersQuantity,
        public int $salesAmountMinor,
        public int $salesQuantity,
        public int $returnsAmountMinor,
        public int $returnsQuantity,
        public int $advertisingMinor,
        public ?int $costPerOrderMinor,
        public ?int $drrOrdersBps,
        public ?int $costPerSaleMinor,
        public ?int $drrSalesBps,
        public int $deliveryLogisticsMinor,
        public int $deliveryCount,
        public int $returnLogisticsMinor,
        public int $returnLogisticsCount,
        /** Прогноз выкупа когорты заказов периода (ADR-029), как на экране «Выкуп». */
        public ?int $buyoutRateBps,
        public ?int $buyoutQuantity,
        public int $buyoutOrderedQuantity,
        /** Доля заказов периода с известным исходом: остальные ещё созревают. */
        public ?int $buyoutResolutionRateBps,
        public ?int $profitMinor,
    ) {
    }
}
