<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\NetForecastQuantities;
use App\Ingestion\Domain\ValueObject\NetForecastRevenue;
use App\Shared\Domain\ValueObject\Money;

final readonly class NetForecastDay
{
    /** @param list<NetForecastGroup> $groups */
    public function __construct(
        public string $businessDate,
        public array $groups,
        public ?NetForecastQuantities $quantities,
        public ?string $unavailableReason,
        public ?Money $expectedNetRevenue,
        public ?string $moneyUnavailableReason,
        private NetForecastRevenue $revenue,
    ) {
    }

    public function exactRevenue(): NetForecastRevenue
    {
        return $this->revenue;
    }
}
