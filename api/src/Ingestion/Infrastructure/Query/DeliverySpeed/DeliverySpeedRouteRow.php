<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\DeliverySpeed;

final readonly class DeliverySpeedRouteRow
{
    public function __construct(
        public string $clusterFrom,
        public string $clusterTo,
        public bool $local,
        public DeliverySpeedMetrics $metrics,
    ) {
    }
}
