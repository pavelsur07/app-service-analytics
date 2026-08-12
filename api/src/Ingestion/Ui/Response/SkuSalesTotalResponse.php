<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response;

/**
 * Денежные величины — целое число минорных единиц плюс код валюты
 * (ADR-004, CLAUDE.md §3). Числа с плавающей точкой в контракте
 * не появляются: округлять и форматировать — дело клиента, и делать это
 * он обязан из минорных единиц.
 */
final readonly class SkuSalesTotalResponse
{
    public function __construct(
        public string $currency,
        public int $orderedQuantity,
        public int $orderedAmountMinor,
        public int $deliveredQuantity,
        public int $deliveredAmountMinor,
        public int $cancelledQuantity,
        public int $cancelledAmountMinor,
    ) {
    }
}
