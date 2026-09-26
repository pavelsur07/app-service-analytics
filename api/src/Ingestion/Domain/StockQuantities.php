<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Остаток по видам в штуках (ADR-034). Потоки (outbound_*,
 * inbound_replenishment) — не остаток и сюда не входят.
 */
final readonly class StockQuantities
{
    public function __construct(
        public int $available,
        public int $transit,
        public int $requested,
        public int $returnFromCustomer,
        public int $returnToSeller,
        public int $defect,
        public int $other,
    ) {
        foreach (get_object_vars($this) as $name => $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException("Stock quantity {$name} must not be negative.");
            }
        }
    }
}
