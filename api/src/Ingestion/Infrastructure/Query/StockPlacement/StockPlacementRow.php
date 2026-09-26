<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\StockPlacement;

final readonly class StockPlacementRow
{
    public function __construct(
        public string $marketplaceSku,
        public ?string $offerId,
        public ?string $name,
        public string $cluster,
        public ?int $available,
        public ?int $transit,
        public ?int $requested,
        public int $sold,
        /** Штук в день × 1000 — без float в контракте. */
        public int $demandMilliPerDay,
        public ?int $coverDays,
        public ?int $recommended,
        public string $status,
        public string $abcClass,
        public int $zeroDays,
        public ?int $lostHours,
        public ?string $adsCluster,
        public ?int $idcCluster,
        public int $priority,
    ) {
    }
}
