<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\Localization;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['from', 'to', 'definitions', 'summary', 'clusters', 'clustersTruncated', 'items', 'nextCursor'])]
final readonly class LocalizationReportResponse
{
    /**
     * @param list<LocalizationClusterResponse> $clusters
     * @param list<LocalizationSkuResponse>     $items
     */
    public function __construct(
        public string $from,
        public string $to,
        public LocalizationDefinitionsResponse $definitions,
        public LocalizationMetricsResponse $summary,
        public array $clusters,
        public bool $clustersTruncated,
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
