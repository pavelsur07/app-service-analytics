<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\SalesCohort;

final readonly class SalesCohortSeriesRow
{
    /** @param list<SalesCohortSeriesPointRow> $points */
    public function __construct(public string $marketplaceSku, public array $points)
    {
    }
}
