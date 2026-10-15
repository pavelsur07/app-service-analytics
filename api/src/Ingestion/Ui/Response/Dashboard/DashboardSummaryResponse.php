<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\Dashboard;

use App\Ingestion\Application\Dashboard\DashboardSummary;
use OpenApi\Attributes as OA;

final readonly class DashboardSummaryResponse
{
    public function __construct(
        public string $currency,
        /** Первый день, с которого загружены и отправления, и начисления; NULL — данных нет. */
        #[OA\Property(format: 'date')]
        public ?string $dataStartsOn,
        public DashboardPeriodResponse $current,
        public DashboardPeriodResponse $comparison,
        public DashboardChangesResponse $changes,
    ) {
    }

    public static function from(DashboardSummary $summary): self
    {
        return new self(
            $summary->currency,
            $summary->dataStartsOn,
            DashboardPeriodResponse::from($summary->current),
            DashboardPeriodResponse::from($summary->comparison),
            DashboardChangesResponse::from($summary->changes),
        );
    }
}
