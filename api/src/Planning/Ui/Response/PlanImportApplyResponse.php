<?php

declare(strict_types=1);

namespace App\Planning\Ui\Response;

final readonly class PlanImportApplyResponse
{
    public function __construct(
        public string $previewId,
        public PlanImportApplySummaryResponse $summary,
    ) {
    }
}
