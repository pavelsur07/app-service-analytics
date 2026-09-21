<?php

declare(strict_types=1);

namespace App\Planning\Application;

final readonly class ApplyPlanImportResult
{
    /** @param array<string, int>|null $summary */
    public function __construct(
        public PlanImportApplyOutcome $outcome,
        public ?array $summary = null,
    ) {}
}
