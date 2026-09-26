<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Localization;

use App\Ingestion\Infrastructure\Query\Localization\LocalizationClusterRow;
use App\Ingestion\Infrastructure\Query\Localization\LocalizationMetrics;
use App\Ingestion\Infrastructure\Query\Localization\LocalizationSkuCursor;
use App\Ingestion\Infrastructure\Query\Localization\LocalizationSkuRow;

final readonly class LocalizationReport
{
    /**
     * @param list<LocalizationClusterRow> $clusters
     * @param list<LocalizationSkuRow>     $skus
     */
    public function __construct(
        public LocalizationMetrics $summary,
        public array $clusters,
        public bool $clustersTruncated,
        public array $skus,
        public ?LocalizationSkuCursor $nextCursor,
    ) {
    }
}
