<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\Dashboard;

use App\Ingestion\Application\Dashboard\DashboardDay;

/** Суммы одного дня — точка спарклайна; расходы и возвраты модулем. */
final readonly class DashboardDayResponse
{
    public function __construct(
        public string $date,
        public int $ordersAmountMinor,
        public int $salesAmountMinor,
        public int $returnsAmountMinor,
        public int $advertisingMinor,
        public int $deliveryLogisticsMinor,
        public int $returnLogisticsMinor,
    ) {
    }

    public static function from(DashboardDay $day): self
    {
        return new self(
            $day->date,
            $day->ordersAmountMinor,
            $day->salesAmountMinor,
            $day->returnsAmountMinor,
            $day->advertisingMinor,
            $day->deliveryLogisticsMinor,
            $day->returnLogisticsMinor,
        );
    }
}
