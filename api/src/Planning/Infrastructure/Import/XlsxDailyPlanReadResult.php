<?php

declare(strict_types=1);

namespace App\Planning\Infrastructure\Import;

final readonly class XlsxDailyPlanReadResult
{
    /**
     * @param list<PlanImportRow>   $rows
     * @param list<PlanImportIssue> $issues
     */
    public function __construct(
        public array $rows,
        public array $issues,
    ) {
    }
}
