<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\NetForecastQuantities;

final readonly class NetForecastDay
{
    /** @param list<NetForecastGroup> $groups */
    public function __construct(
        public string $businessDate,
        public array $groups,
        public ?NetForecastQuantities $quantities,
        public ?string $unavailableReason,
    ) {
    }
}
