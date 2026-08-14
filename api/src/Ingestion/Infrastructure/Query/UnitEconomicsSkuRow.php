<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

/**
 * Строка отчёта по артикулу за период (CLAUDE.md §5): продажи и итог
 * расходов уже сведены запросом. Денежные величины — минорные единицы
 * плюс код валюты (ADR-004).
 */
final readonly class UnitEconomicsSkuRow
{
    public function __construct(
        public string $marketplaceSku,
        public string $currency,
        public int $deliveredQuantity,
        public int $deliveredAmountMinor,
        public int $commissionAmountMinor,
        public int $orderedQuantity,
        public int $expensesTotalMinor,
    ) {
    }
}
