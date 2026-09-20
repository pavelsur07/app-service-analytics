<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

final readonly class PlanningResolutionTotalRow
{
    public function __construct(
        public string $marketplaceSku,
        public int $delivered,
        public int $returned,
        public int $preHandoverNoBuy,
        public int $postHandoverNoBuy,
        public int $otherTerminalNoBuy,
    ) {
    }
}
