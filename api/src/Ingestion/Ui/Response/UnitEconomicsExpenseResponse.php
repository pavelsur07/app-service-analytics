<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response;

/**
 * Расход одного типа. Денежная величина — минорные единицы (ADR-004),
 * форматирование делает клиент.
 */
final readonly class UnitEconomicsExpenseResponse
{
    public function __construct(
        public int $feeTypeId,
        public string $name,
        public int $amountMinor,
    ) {
    }
}
