<?php

declare(strict_types=1);

namespace App\Links\Infrastructure\Query;

final readonly class DailyClicksRow
{
    public function __construct(
        public string $date,
        public int $clicks,
    ) {
    }
}
