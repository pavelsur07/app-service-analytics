<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Dashboard;

/** Компоненты со знаками; costTotalMinor — известная часть при quantityWithoutCost > 0. */
final readonly class DashboardProfitDetails
{
    public function __construct(
        public bool $hasAccruals,
        public int $accrualsTotalMinor,
        public int $costTotalMinor,
        public int $quantityWithoutCost,
        public string $costMethod,
        public ?string $costCorrectedAt,
    ) {
    }
}
