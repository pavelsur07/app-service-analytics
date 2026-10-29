<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\NetForecastFutureT1;
use App\Ingestion\Domain\ValueObject\NetForecastQuantities;
use App\Ingestion\Domain\ValueObject\NetForecastRevenue;
use App\Shared\Domain\ValueObject\Money;

final readonly class NetForecastDay
{
    private NetForecastFutureT1 $futureT1;

    /** @param list<NetForecastGroup> $groups */
    public function __construct(
        public string $businessDate,
        public array $groups,
        public ?NetForecastQuantities $quantities,
        public ?string $unavailableReason,
        public ?Money $expectedNetRevenue,
        public ?string $moneyUnavailableReason,
        private NetForecastRevenue $revenue,
        ?NetForecastFutureT1 $futureT1 = null,
    ) {
        $this->futureT1 = $futureT1 ?? NetForecastFutureT1::unavailable('future_t1_unavailable');
    }

    public function futureT1(): NetForecastFutureT1
    {
        return $this->futureT1;
    }

    public function exactRevenue(): NetForecastRevenue
    {
        return $this->revenue;
    }
}
