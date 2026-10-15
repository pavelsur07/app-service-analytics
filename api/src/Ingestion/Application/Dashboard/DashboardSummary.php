<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Dashboard;

final readonly class DashboardSummary
{
    public function __construct(
        public string $currency,
        /** Позднейший из первых загруженных дней отправлений и начислений; NULL — данных нет. */
        public ?string $dataStartsOn,
        public DashboardPeriodSummary $current,
        public DashboardPeriodSummary $comparison,
        public DashboardChanges $changes,
    ) {
    }
}
