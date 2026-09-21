<?php

declare(strict_types=1);

namespace App\Planning\Infrastructure\Import;

final readonly class PlanImportRow
{
    public function __construct(
        public int $rowNumber,
        public string $marketplaceSku,
        public string $businessDate,
        public int $quantity,
    ) {
    }
}
