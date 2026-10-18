<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\Ingestion\Application\Facade\CatalogSkuPage;
use App\Ingestion\Application\Facade\IngestionFacade;

/**
 * Выбор SKU в план — каталог подключения (ADR-040 п. 4). Ui границу
 * модуля не пересекает даже через Facade, поэтому вызов идёт отсюда.
 * Чужое подключение даёт пустую страницу: Facade фильтрует компанию.
 */
final readonly class SearchPlanCatalogSkusAction
{
    public function __construct(
        private IngestionFacade $ingestion,
    ) {
    }

    public function __invoke(string $companyId, string $marketplaceAccountId, string $search, ?string $cursor, int $limit): CatalogSkuPage
    {
        return $this->ingestion->searchAccountCatalogSkus($companyId, $marketplaceAccountId, $search, $cursor, $limit);
    }
}
