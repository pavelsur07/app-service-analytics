<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\DeliverySpeed;

final readonly class DeliverySpeedClusterRow
{
    public function __construct(
        public string $clusterTo,
        public DeliverySpeedMetrics $metrics,
        public ?int $lostHours,
    ) {
    }
}
