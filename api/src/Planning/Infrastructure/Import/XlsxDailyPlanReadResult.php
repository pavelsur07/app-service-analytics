<?php

declare(strict_types=1);

namespace App\Planning\Infrastructure\Import;

use App\Planning\Domain\PlanImportIssue;

final readonly class XlsxDailyPlanReadResult
{
    /**
     * @param list<PlanImportRow>          $rows
     * @param list<PlanImportIssue>        $issues
     * @param list<PlanImportSkuReference> $skuReferences
     */
    public function __construct(
        public array $rows,
        public array $issues,
        public array $skuReferences = [],
    ) {
    }
}
