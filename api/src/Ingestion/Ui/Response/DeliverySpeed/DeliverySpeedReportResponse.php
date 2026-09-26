<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\DeliverySpeed;

use OpenApi\Attributes as OA;

#[OA\Schema(required: [
    'from', 'to', 'definitions', 'periodPostings', 'summary', 'clusters', 'clustersTruncated',
    'routes', 'routesTruncated', 'items', 'nextCursor', 'buyoutBySpeed',
])]
final readonly class DeliverySpeedReportResponse
{
    /**
     * @param list<DeliverySpeedClusterResponse> $clusters
     * @param list<DeliverySpeedRouteResponse>   $routes
     * @param list<DeliverySpeedSkuResponse>     $items
     * @param list<DeliverySpeedBucketResponse>  $buyoutBySpeed
     */
    public function __construct(
        public string $from,
        public string $to,
        public DeliverySpeedDefinitionsResponse $definitions,
        /** Все отправления периода, включая не наблюдавшиеся вживую. */
        public int $periodPostings,
        public DeliverySpeedMetricsResponse $summary,
        public array $clusters,
        public bool $clustersTruncated,
        public array $routes,
        public bool $routesTruncated,
        public array $items,
        public ?string $nextCursor,
        public array $buyoutBySpeed,
    ) {
    }
}
