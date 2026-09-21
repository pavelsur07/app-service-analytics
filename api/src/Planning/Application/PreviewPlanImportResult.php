<?php

declare(strict_types=1);

namespace App\Planning\Application;

use App\Planning\Domain\PlanImportPreview;
use App\Planning\Infrastructure\Import\PlanImportIssue;

final readonly class PreviewPlanImportResult
{
    /** @param list<PlanImportIssue> $issues */
    public function __construct(
        public PlanImportPreviewOutcome $outcome,
        public ?PlanImportPreview $preview,
        public array $issues,
    ) {}
}
