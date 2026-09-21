<?php

declare(strict_types=1);

namespace App\Planning\Infrastructure\Query;

final readonly class DailyPlanRow
{
    public function __construct(
        public string $businessDate,
        public ?int $quantity,
        public int $version,
    ) {
    }
}
