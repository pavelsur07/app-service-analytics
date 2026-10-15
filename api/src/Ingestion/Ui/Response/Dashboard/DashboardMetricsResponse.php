<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\Dashboard;

use App\Ingestion\Application\Dashboard\DashboardMetrics;

/**
 * Показатели одного периода. Суммы — минорные единицы валюты сводки;
 * реклама, возвраты и логистика — модулем (сколько потрачено). ДРР
 * и выкуп — базисные пункты (1250 = 12,5 %). NULL — считать не из чего:
 * ноль в знаменателе или выкуп без заказов.
 */
final readonly class DashboardMetricsResponse
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
        public ?int $buyoutRateBps,
        public ?int $buyoutQuantity,
        public int $buyoutOrderedQuantity,
        public ?int $buyoutResolutionRateBps,
    ) {
    }

    public static function from(DashboardMetrics $metrics): self
    {
        return new self(
            $metrics->ordersAmountMinor,
            $metrics->ordersQuantity,
            $metrics->salesAmountMinor,
            $metrics->salesQuantity,
            $metrics->returnsAmountMinor,
            $metrics->returnsQuantity,
            $metrics->advertisingMinor,
            $metrics->costPerOrderMinor,
            $metrics->drrOrdersBps,
            $metrics->costPerSaleMinor,
            $metrics->drrSalesBps,
            $metrics->deliveryLogisticsMinor,
            $metrics->deliveryCount,
            $metrics->returnLogisticsMinor,
            $metrics->returnLogisticsCount,
            $metrics->buyoutRateBps,
            $metrics->buyoutQuantity,
            $metrics->buyoutOrderedQuantity,
            $metrics->buyoutResolutionRateBps,
        );
    }
}
