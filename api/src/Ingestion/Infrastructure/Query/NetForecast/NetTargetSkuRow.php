<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

final readonly class NetTargetSkuRow
{
    /** @param list<NetTargetDayRow> $days */
    public function __construct(
        public string $marketplaceSku,
        public array $days,
    ) {
    }
}
