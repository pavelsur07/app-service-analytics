<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\DeliverySpeed;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['clusterTo', 'metrics', 'lostHours'])]
final readonly class DeliverySpeedClusterResponse
{
    public function __construct(
        public string $clusterTo,
        public DeliverySpeedMetricsResponse $metrics,
        /** Потерянные часы ожидания; null — не хватает локальных или нелокальных отправлений. */
        public ?int $lostHours,
    ) {
    }
}
