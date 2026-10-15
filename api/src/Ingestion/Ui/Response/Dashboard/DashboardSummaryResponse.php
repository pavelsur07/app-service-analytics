<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\Dashboard;

use App\Ingestion\Application\Dashboard\DashboardSummary;

final readonly class DashboardSummaryResponse
{
    public function __construct(
        public string $currency,
        public DashboardPeriodResponse $current,
        public DashboardPeriodResponse $comparison,
        public DashboardChangesResponse $changes,
    ) {
    }

    public static function from(DashboardSummary $summary): self
    {
        return new self(
            $summary->currency,
            DashboardPeriodResponse::from($summary->current),
            DashboardPeriodResponse::from($summary->comparison),
            DashboardChangesResponse::from($summary->changes),
        );
    }
}
