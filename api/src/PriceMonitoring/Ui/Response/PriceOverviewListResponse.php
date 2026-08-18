<?php

declare(strict_types=1);

namespace App\PriceMonitoring\Ui\Response;

final readonly class PriceOverviewListResponse
{
    /**
     * @param list<PriceOverviewItemResponse> $items
     */
    public function __construct(
        public array $items,
    ) {
    }
}
