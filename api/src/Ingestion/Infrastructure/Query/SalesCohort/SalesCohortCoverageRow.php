<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\SalesCohort;

/** Полнота дневной выгрузки заказов; не полнота исходов или возвратов. */
final readonly class SalesCohortCoverageRow
{
    /** @param 'complete'|'missing'|'partial' $ordersCoverage */
    public function __construct(
        public string $date,
        public string $ordersCoverage,
    ) {
    }
}
