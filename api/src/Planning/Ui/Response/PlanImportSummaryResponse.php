<?php

declare(strict_types=1);

namespace App\Planning\Ui\Response;

final readonly class PlanImportSummaryResponse
{
    public function __construct(
        public int $total,
        public int $new,
        public int $changed,
        public int $unchanged,
    ) {}
}
