<?php

declare(strict_types=1);

namespace App\Planning\Application;

use App\Planning\Domain\PlanImportIssue;
use App\Planning\Domain\PlanImportPreview;

final readonly class PreviewPlanImportResult
{
    /** @param list<PlanImportIssue> $issues */
    public function __construct(
        public PlanImportPreviewOutcome $outcome,
        public ?PlanImportPreview $preview,
        public array $issues,
    ) {
    }
}
