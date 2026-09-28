<?php

declare(strict_types=1);

namespace App\Ingestion\Application\UnitEconomics;

/** Статья начисления — один тип площадки — за период. */
final readonly class AccrualReconciliationItem
{
    public function __construct(
        public int $feeTypeId,
        public string $name,
        public int $amountMinor,
    ) {
    }
}
