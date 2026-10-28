<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\NetForecastQuantities;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetSourceTrace;
use App\Shared\Domain\ValueObject\Money;

final readonly class NetForecastPart
{
    public function __construct(
        public string $sourceRowId,
        public ?NetForecastQuantities $quantities,
        public NetForecastComponent $delivered,
        public NetForecastComponent $openPre,
        public NetForecastComponent $openPost,
        public NetTargetSourceTrace $trace,
        public ?string $unavailableReason,
        public ?Money $expectedNetRevenue,
        public ?string $moneyUnavailableReason,
    ) {
    }
}
