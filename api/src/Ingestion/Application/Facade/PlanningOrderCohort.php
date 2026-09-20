<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

final readonly class PlanningOrderCohort
{
    public function __construct(
        public string $marketplaceSku,
        public string $orderBusinessDate,
        public int $ordered,
        public int $bought,
        public int $terminalNoBuy,
        public int $openEligible,
        public int $unknown,
        /** @var list<string> */
        public array $rawDocumentIds,
        public bool $rawDocumentIdsTruncated,
    ) {
    }
}
