<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Localization;

final readonly class LocalizationClusterRow
{
    /**
     * @param list<LocalizationSourceCluster> $topSources главные кластеры отгрузки, по убыванию штук
     */
    public function __construct(
        public string $clusterTo,
        public LocalizationMetrics $metrics,
        public array $topSources,
    ) {
    }
}
