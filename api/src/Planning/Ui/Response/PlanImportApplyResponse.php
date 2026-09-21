<?php

declare(strict_types=1);

namespace App\Planning\Ui\Response;

final readonly class PlanImportApplyResponse
{
    /** @param array<string, int> $summary */
    public function __construct(
        public string $previewId,
        public array $summary,
    ) {}
}
