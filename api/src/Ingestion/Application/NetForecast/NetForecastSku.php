<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\NetForecastQuantities;

final readonly class NetForecastSku
{
    /** @param list<NetForecastDay> $days */
    public function __construct(
        public string $marketplaceSku,
        public array $days,
        public ?NetForecastQuantities $quantities,
        public ?string $unavailableReason,
    ) {
    }
}
