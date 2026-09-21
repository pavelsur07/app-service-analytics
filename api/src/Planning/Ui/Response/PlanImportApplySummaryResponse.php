<?php

declare(strict_types=1);

namespace App\Planning\Ui\Response;

final readonly class PlanImportApplySummaryResponse
{
    public function __construct(
        public int $created,
        public int $updated,
        public int $unchanged,
    ) {
    }
}
