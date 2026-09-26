<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\Localization;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['clusterTo', 'metrics', 'topSources'])]
final readonly class LocalizationClusterResponse
{
    /** @param list<LocalizationSourceClusterResponse> $topSources */
    public function __construct(
        public string $clusterTo,
        public LocalizationMetricsResponse $metrics,
        public array $topSources,
    ) {
    }
}
