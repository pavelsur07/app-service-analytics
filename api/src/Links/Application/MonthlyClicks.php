<?php

declare(strict_types=1);

namespace App\Links\Application;

final readonly class MonthlyClicks
{
    /**
     * @param list<array{date: string, clicks: int}> $items
     */
    public function __construct(
        public string $linkId,
        public string $month,
        public array $items,
    ) {
    }
}
