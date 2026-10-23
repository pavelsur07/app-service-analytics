<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\DemandMatrix;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['today', 'definitions', 'clusters', 'incompleteSalesAccounts', 'items', 'nextCursor'])]
final readonly class DemandMatrixReportResponse
{
    /**
     * @param list<DemandMatrixClusterResponse> $clusters
     * @param list<DemandMatrixItemResponse>    $items
     */
    public function __construct(
        public string $today,
        public DemandMatrixDefinitionsResponse $definitions,
        /** Колонки: больше заказов — левее, «кластер не указан» — последней. Одинаковы на всех страницах. */
        public array $clusters,
        /** Подключения Ozon, чьи продажи окна загружены не полностью: цифры могут быть занижены. */
        public int $incompleteSalesAccounts,
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
