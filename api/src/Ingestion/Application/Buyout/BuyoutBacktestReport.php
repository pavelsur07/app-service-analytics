<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Buyout;

final readonly class BuyoutBacktestReport
{
    /**
     * @param list<BuyoutBacktestPair>   $pairs
     * @param list<BuyoutBacktestBucket> $buckets
     */
    public function __construct(
        public \DateTimeImmutable $earliestAsOfDate,
        public \DateTimeImmutable $fromAsOfDate,
        public \DateTimeImmutable $toAsOfDate,
        public array $pairs,
        public array $buckets,
    ) {
    }
}
