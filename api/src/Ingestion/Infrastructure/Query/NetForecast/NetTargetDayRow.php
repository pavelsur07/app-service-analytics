<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

final readonly class NetTargetDayRow
{
    /** @param list<NetTargetGroupRow> $groups */
    public function __construct(
        public string $businessDate,
        public array $groups,
        public bool $emptyComplete,
        public ?string $unavailableReason,
    ) {
    }
}
