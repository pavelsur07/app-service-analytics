<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\DeliverySpeed;

use OpenApi\Attributes as OA;

#[OA\Schema(required: [
    'marketplaceSku', 'offerId', 'name', 'clusterTo', 'quantity', 'nonlocalQuantity',
    'nonlocalArrivedPostings', 'clusterMedianLocalSeconds', 'clusterMedianNonlocalSeconds', 'lostHours',
])]
final readonly class DeliverySpeedSkuResponse
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
