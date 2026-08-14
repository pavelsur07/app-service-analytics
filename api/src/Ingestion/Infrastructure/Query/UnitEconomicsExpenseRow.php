<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

/**
 * Строка расходов по артикулу и типу за период (CLAUDE.md §5).
 * Пустой артикул — расход кабинета, к товару не привязанный.
 */
final readonly class UnitEconomicsExpenseRow
{
    public function __construct(
        public string $marketplaceSku,
        public int $feeTypeId,
        public string $currency,
        public int $amountMinor,
    ) {
    }
}
