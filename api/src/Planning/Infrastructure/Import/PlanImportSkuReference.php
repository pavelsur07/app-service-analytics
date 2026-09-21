<?php

declare(strict_types=1);

namespace App\Planning\Infrastructure\Import;

final readonly class PlanImportSkuReference
{
    public function __construct(
        public int $rowNumber,
        public string $marketplaceSku,
    ) {
    }
}
