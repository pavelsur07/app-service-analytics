<?php

declare(strict_types=1);

namespace App\SalesPlanning\Domain;

final readonly class ClaimedSalesPlanDay
{
    public function __construct(
        public string $marketplaceSku,
        public string $date,
    ) {
    }
}
