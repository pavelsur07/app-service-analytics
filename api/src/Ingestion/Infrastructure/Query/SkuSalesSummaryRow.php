<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

/**
 * Итог по одному артикулу в одной валюте. Валюта — часть строки, а не
 * общий заголовок ответа: складывать суммы разных валют запрещено
 * (CLAUDE.md §3), поэтому итог считается по каждой отдельно.
 *
 * Три категории ADR-009 — заказано, доставлено, отменено — считаются
 * и отдаются раздельно. Доставленное входит в заказанное; свернуть их
 * в одно число значило бы показать как продажу то, что ещё в пути
 * и может отмениться. Вычитать одно из другого — решение потребителя,
 * не наше.
 */
final readonly class SkuSalesSummaryRow
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
