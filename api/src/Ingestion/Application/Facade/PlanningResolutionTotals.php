<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

final readonly class PlanningResolutionTotals
{
    /** @param list<PlanningResolutionTotalRow> $items */
    public function __construct(
        public array $items,
        public bool $complete,
        public ?string $lastCompleteAt,
        public string $sourceVersion,
    ) {
    }
}
