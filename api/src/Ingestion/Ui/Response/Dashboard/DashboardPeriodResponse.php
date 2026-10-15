<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\Dashboard;

use App\Ingestion\Application\Dashboard\DashboardPeriodSummary;
use OpenApi\Attributes as OA;

final readonly class DashboardPeriodResponse
{
    /**
     * @param list<DashboardDayResponse> $days
     */
    public function __construct(
        #[OA\Property(format: 'date')]
        public string $from,
        #[OA\Property(format: 'date')]
        public string $to,
        /** Ложь — часть периода пришлась на время до первой загрузки данных. */
        public bool $dataComplete,
        /** Дни, за которые продажи загружены, а начисления нет. */
        public int $daysWithoutAccruals,
        public DashboardMetricsResponse $metrics,
        /** Каждый день периода по возрастанию, без пропусков. */
        public array $days,
    ) {
    }

    public static function from(DashboardPeriodSummary $period): self
    {
        return new self(
            $period->from,
            $period->to,
            $period->dataComplete,
            $period->daysWithoutAccruals,
            DashboardMetricsResponse::from($period->metrics),
            array_map(DashboardDayResponse::from(...), $period->days),
        );
    }
}
