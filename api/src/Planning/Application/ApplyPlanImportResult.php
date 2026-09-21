<?php

declare(strict_types=1);

namespace App\Planning\Application;

final readonly class ApplyPlanImportResult
{
    /** @param array{created: int, updated: int, unchanged: int}|null $summary */
    public function __construct(
        public PlanImportApplyOutcome $outcome,
        public ?array $summary = null,
    ) {
    }
}
