<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\UnitEconomics;

final readonly class AccrualCategoryRow
{
    public function __construct(
        public int $feeTypeId,
        /** Строка выручки со знаком «минус» — возврат (ADR-036). */
        public bool $negativeRevenue,
        public string $currency,
        public int $amountMinor,
    ) {
    }
}
