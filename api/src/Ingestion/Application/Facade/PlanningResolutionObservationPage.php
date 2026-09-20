<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

final readonly class PlanningResolutionObservationPage
{
    /** @param list<PlanningResolutionObservation> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public bool $complete,
        public ?string $lastCompleteAt,
        public string $sourceVersion,
    ) {
    }
}
