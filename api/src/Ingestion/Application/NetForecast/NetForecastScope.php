<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\NetForecastFutureT1;
use App\Ingestion\Domain\ValueObject\NetForecastQuantities;
use App\Shared\Domain\ValueObject\Money;

final readonly class NetForecastScope
{
    public NetForecastFutureT1 $futureT1;

    /** @param list<string> $basis
     * @param list<string> $reasons */
    public function __construct(
        public ?NetForecastQuantities $quantities,
        public ?Money $expectedNetRevenue,
        public ?string $unavailableReason,
        public ?string $moneyUnavailableReason,
        public array $basis,
        public array $reasons,
        ?NetForecastFutureT1 $futureT1 = null,
    ) {
        $this->futureT1 = $futureT1 ?? NetForecastFutureT1::unavailable('future_t1_unavailable');
    }
}
