<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

final readonly class NetForecastSeries
{
    /** @param list<NetForecastSeriesSku> $skus */
    public function __construct(public array $skus, public ?string $unavailableReason = null)
    {
    }
}
