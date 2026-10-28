<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\SalesCohort;

/** Одна строка SKU с ограниченным числом дневных точек. */
final readonly class SalesCohortSkuMetricsRow
{
    /** @param list<SalesCohortMetricsRow> $days */
    public function __construct(
        public string $marketplaceSku,
        public array $days,
    ) {
    }
}
