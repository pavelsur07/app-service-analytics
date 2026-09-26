<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DeliverySpeed;

use App\Ingestion\Infrastructure\Query\DeliverySpeed\DeliverySpeedBucketRow;
use App\Ingestion\Infrastructure\Query\DeliverySpeed\DeliverySpeedClusterRow;
use App\Ingestion\Infrastructure\Query\DeliverySpeed\DeliverySpeedMetrics;
use App\Ingestion\Infrastructure\Query\DeliverySpeed\DeliverySpeedRouteRow;
use App\Ingestion\Infrastructure\Query\DeliverySpeed\DeliverySpeedSkuCursor;
use App\Ingestion\Infrastructure\Query\DeliverySpeed\DeliverySpeedSkuRow;

final readonly class DeliverySpeedReport
{
    /**
     * @param list<DeliverySpeedClusterRow> $clusters
     * @param list<DeliverySpeedRouteRow>   $routes
     * @param list<DeliverySpeedSkuRow>     $skus
     * @param list<DeliverySpeedBucketRow>  $buyoutBySpeed
     */
    public function __construct(
        public int $periodPostings,
        public DeliverySpeedMetrics $summary,
        public array $clusters,
        public bool $clustersTruncated,
        public array $routes,
        public bool $routesTruncated,
        public array $skus,
        public ?DeliverySpeedSkuCursor $nextCursor,
        public array $buyoutBySpeed,
    ) {
    }
}
