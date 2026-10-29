<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

final readonly class NetForecastSeriesPoint
{
    public function __construct(public string $bucketFrom, public string $bucketTo, public NetForecastScope $period, public NetForecastScope $cumulative)
    {
    }
}
