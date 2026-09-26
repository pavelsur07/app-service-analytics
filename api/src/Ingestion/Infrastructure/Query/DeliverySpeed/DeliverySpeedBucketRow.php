<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\DeliverySpeed;

final readonly class DeliverySpeedBucketRow
{
    /**
     * @param int|null $maxDays верхняя граница корзины, дней, не включая; null — без границы
     */
    public function __construct(
        public int $minDays,
        public ?int $maxDays,
        public int $postings,
        public int $deliveredQuantity,
        public int $resolvedQuantity,
        public ?int $buyoutRateBps,
    ) {
    }
}
