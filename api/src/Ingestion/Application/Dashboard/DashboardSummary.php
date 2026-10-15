<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Dashboard;

final readonly class DashboardSummary
{
    public function __construct(
        public string $currency,
        public DashboardPeriodSummary $current,
        public DashboardPeriodSummary $comparison,
        public DashboardChanges $changes,
    ) {
    }
}
