<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\DemandMatrix;

final readonly class DemandMatrixSkuRow
{
    public function __construct(
        public string $marketplaceSku,
        public ?string $offerId,
        public ?string $name,
        public int $orders7,
        public int $orders14,
        public int $orders28,
        /** Заказы 28 дней, у которых Ozon не прислал кластер покупателя. */
        public int $unknownClusterOrders28,
        /** Штук в день × 1000 — без float в контракте. */
        public int $demandMilliPerDay,
        /** 10000 — без изменений; null — меньше MIN_SALES заказов за 28 дней. */
        public ?int $trendBps,
    ) {
    }
}
