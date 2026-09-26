<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Localization;

final readonly class LocalizationSkuRow
{
    public function __construct(
        public string $marketplaceSku,
        public ?string $offerId,
        public ?string $name,
        public string $clusterTo,
        public string $mainSourceCluster,
        public LocalizationMetrics $metrics,
    ) {
    }
}
