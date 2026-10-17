<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

final readonly class SalesPlanSkuResponse
{
    /**
     * @param list<SalesPlanBucketResponse> $buckets пусто при groupBy=period
     */
    public function __construct(
        public string $marketplaceSku,
        public int $totalQuantity,
        public int $revenueMinor,
        public array $buckets,
    ) {
    }
}
