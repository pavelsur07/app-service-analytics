<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\NetForecastQuantities;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetReturnTrace;

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
    ) {
    }
}
