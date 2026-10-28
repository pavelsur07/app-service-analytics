<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\NetForecastQuantities;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetReturnTrace;
use App\Shared\Domain\ValueObject\Money;

final readonly class NetForecastGroup
{
    /** @param list<NetForecastPart> $parts
     * @param list<NetTargetReturnTrace> $returns */
    public function __construct(
        public ?string $orderNumber,
        public string $marketplaceSku,
        public string $businessDate,
        public array $parts,
        public array $returns,
        public ?NetForecastQuantities $quantities,
        public ?string $unavailableReason,
        public ?Money $expectedNetRevenue,
        public ?string $moneyUnavailableReason,
    ) {
    }
}
