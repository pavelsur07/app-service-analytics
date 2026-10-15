<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Dashboard;

final readonly class DashboardPeriodSummary
{
    /**
     * @param list<DashboardDay> $days каждый день периода по возрастанию, без пропусков
     */
    public function __construct(
        public string $from,
        public string $to,
        /**
         * Период начинается не раньше первого загруженного дня обоих
         * источников. Ложь — часть дней пришлась на время до подключения,
         * и сравнивать с ним нельзя.
         */
        public bool $dataComplete,
        /** Дни, за которые продажи загружены, а начисления нет. */
        public int $daysWithoutAccruals,
        public DashboardMetrics $metrics,
        public array $days,
    ) {
    }
}
