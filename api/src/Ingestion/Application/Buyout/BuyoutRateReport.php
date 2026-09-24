<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Buyout;

final readonly class BuyoutRateReport
{
    /**
     * @param list<BuyoutRateSku> $items
     */
    public function __construct(
        public BuyoutRateSummary $summary,
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
