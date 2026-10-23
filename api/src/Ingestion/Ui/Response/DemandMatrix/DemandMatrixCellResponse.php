<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\DemandMatrix;

use OpenApi\Attributes as OA;

/**
 * Ячейка SKU × кластер покупателя. Отдаётся, если были заказы или дни
 * нулевого остатка: пустая ячейка с дефицитом — «спрос неизвестен».
 */
#[OA\Schema(required: [
    'cluster', 'orders7', 'orders14', 'orders28', 'demandMilliPerDay', 'shareBps', 'trendBps', 'stockoutDays', 'demandCensored',
])]
final readonly class DemandMatrixCellResponse
{
    public function __construct(
        /** null — Ozon не прислал кластер покупателя. */
        public ?string $cluster,
        public int $orders7,
        public int $orders14,
        public int $orders28,
        /** Штук в день × 1000 — без float в контракте. */
        public int $demandMilliPerDay,
        /** Доля в заказах SKU по известным кластерам, базисные пункты; null — у ячейки без кластера и когда у SKU нет заказов с известным кластером. */
        public ?int $shareBps,
        /** (заказы 7 дней ÷ 7) ÷ (заказы 28 дней ÷ 28), базисные пункты; null — меньше minSales заказов. */
        public ?int $trendBps,
        /** Полные дни окна без доступного остатка в кластере. */
        public int $stockoutDays,
        /** Были дни без остатка: спрос в ячейке занижен. */
        public bool $demandCensored,
    ) {
    }
}
