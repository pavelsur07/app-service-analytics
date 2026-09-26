<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\DeliverySpeed;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['minDays', 'maxDays', 'postings', 'deliveredQuantity', 'resolvedQuantity', 'buyoutRateBps'])]
final readonly class DeliverySpeedBucketResponse
{
    public function __construct(
        public int $minDays,
        /** Верхняя граница, не включая; null — без границы. */
        public ?int $maxDays,
        public int $postings,
        public int $deliveredQuantity,
        public int $resolvedQuantity,
        public ?int $buyoutRateBps,
    ) {
    }
}
