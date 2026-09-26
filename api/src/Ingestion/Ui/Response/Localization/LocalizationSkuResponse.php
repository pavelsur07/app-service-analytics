<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\Localization;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['marketplaceSku', 'offerId', 'name', 'clusterTo', 'mainSourceCluster', 'metrics'])]
final readonly class LocalizationSkuResponse
{
    public function __construct(
        public string $marketplaceSku,
        public ?string $offerId,
        public ?string $name,
        public string $clusterTo,
        public string $mainSourceCluster,
        public LocalizationMetricsResponse $metrics,
    ) {
    }
}
