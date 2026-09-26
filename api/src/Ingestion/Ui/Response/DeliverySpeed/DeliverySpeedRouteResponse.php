<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\DeliverySpeed;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['clusterFrom', 'clusterTo', 'local', 'metrics'])]
final readonly class DeliverySpeedRouteResponse
{
    public function __construct(
        public string $clusterFrom,
        public string $clusterTo,
        public bool $local,
        public DeliverySpeedMetricsResponse $metrics,
    ) {
    }
}
