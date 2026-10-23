<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\DemandMatrix;

use OpenApi\Attributes as OA;

#[OA\Schema(required: [
    'marketplaceSku', 'offerId', 'name', 'orders7', 'orders14', 'orders28', 'unknownClusterOrders28',
    'demandMilliPerDay', 'trendBps', 'cells',
])]
final readonly class DemandMatrixItemResponse
{
    /**
     * @param list<DemandMatrixCellResponse> $cells
     */
    public function __construct(
        public string $marketplaceSku,
        public ?string $offerId,
        public ?string $name,
        public int $orders7,
        public int $orders14,
        public int $orders28,
        /** Заказы 28 дней без кластера покупателя. */
        public int $unknownClusterOrders28,
        /** Штук в день × 1000 — без float в контракте. */
        public int $demandMilliPerDay,
        /** Базисные пункты, 10000 — без изменений; null — меньше minSales заказов. */
        public ?int $trendBps,
        public array $cells,
    ) {
    }
}
