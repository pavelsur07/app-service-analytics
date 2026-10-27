<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

use App\SalesPlanning\Domain\SalesPlanMetric;

final readonly class SalesPlanMetricResponse
{
    public function __construct(public string $id, public int $version, public SalesPlanMetric $metric)
    {
    }
}
