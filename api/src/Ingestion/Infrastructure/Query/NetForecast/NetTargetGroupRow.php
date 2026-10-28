<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

final readonly class NetTargetGroupRow
{
    /** @param list<NetTargetPartRow> $parts
     * @param list<NetTargetReturnTrace> $returns */
    public function __construct(
        public ?string $orderNumber,
        public string $marketplaceSku,
        public string $businessDate,
        public array $parts,
        public array $returns,
        public bool $inputComplete,
        public ?string $unavailableReason,
    ) {
    }
}
