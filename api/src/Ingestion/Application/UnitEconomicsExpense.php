<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

final readonly class UnitEconomicsExpense
{
    public function __construct(
        public int $feeTypeId,
        public string $name,
        public int $amountMinor,
    ) {
    }
}
