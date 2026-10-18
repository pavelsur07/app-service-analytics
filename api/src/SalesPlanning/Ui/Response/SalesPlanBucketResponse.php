<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

final readonly class SalesPlanBucketResponse
{
    public function __construct(
        public string $dateFrom,
        public string $dateTo,
        public int $quantity,
        public int $revenueMinor,
    ) {
    }
}
