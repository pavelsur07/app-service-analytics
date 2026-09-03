<?php

declare(strict_types=1);

namespace App\Links\Ui\Response;

final readonly class DailyClicksResponse
{
    public function __construct(
        public string $date,
        public int $clicks,
    ) {
    }
}
