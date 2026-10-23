<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\DemandMatrix;

final readonly class DemandMatrixCellRow
{
    public function __construct(
        public string $marketplaceSku,
        /** null — Ozon не прислал кластер покупателя. */
        public ?string $cluster,
        public int $orders7,
        public int $orders14,
        public int $orders28,
        /** Штук в день × 1000 — без float в контракте. */
        public int $demandMilliPerDay,
        /** Доля в заказах SKU по известным кластерам, базисные пункты; null — у ячейки без кластера и когда у SKU нет заказов с известным кластером. */
        public ?int $shareBps,
        public ?int $trendBps,
        /** Полные дни окна без доступного остатка в кластере; > 0 — спрос занижен. */
        public int $stockoutDays,
    ) {
    }
}
