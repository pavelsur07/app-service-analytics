<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

final readonly class PlanningResolutionObservation
{
    public function __construct(
        public string $marketplaceSku,
        public string $sourceRowId,
        public string $allocationKey,
        public string $outcome,
        public int $quantity,
        public ?string $firstKnownOutcomeAt,
        public ?string $firstRegularlyObservedAt,
        public ?string $sourceEventAt,
        public bool $backfill,
        public ?string $rawDocumentId,
    ) {
    }
}
