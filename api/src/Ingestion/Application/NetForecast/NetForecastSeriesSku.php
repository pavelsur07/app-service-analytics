<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

final readonly class NetForecastSeriesSku
{
    /** @param list<NetForecastSeriesPoint> $points */
    public function __construct(public string $marketplaceSku, public array $points)
    {
    }
}
