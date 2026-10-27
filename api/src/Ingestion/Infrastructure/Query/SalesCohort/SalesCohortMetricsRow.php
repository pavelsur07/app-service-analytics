<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\SalesCohort;

/** Количества одной SKU за бизнес-день заказа; исходные факты не изменяются. */
final readonly class SalesCohortMetricsRow
{
    public function __construct(
        public string $marketplaceSku,
        public string $date,
        public int $rawOrderedQuantity,
        public int $preHandoverCancelledQuantity,
        public int $effectiveOrderedQuantity,
        /** D уже исключает количество клиентских возвратов R. */
        public int $observedNetSalesQuantity,
    ) {
    }
}
