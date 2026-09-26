<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\DeliverySpeed;

final readonly class DeliverySpeedSkuRow
{
    public function __construct(
        public string $marketplaceSku,
        public ?string $offerId,
        public ?string $name,
        public string $clusterTo,
        public int $quantity,
        public int $nonlocalQuantity,
        public int $nonlocalArrivedPostings,
        public int $clusterMedianLocalSeconds,
        public int $clusterMedianNonlocalSeconds,
        public int $lostHours,
    ) {
    }
}
