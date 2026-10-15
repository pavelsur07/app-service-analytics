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
         * Каждое подключение, жившее в периоде, загрузило отправления
         * и начисления с первого его дня по последний, и дней с продажами
         * без начислений нет. Ложь — сравнивать с периодом нельзя.
         */
        public bool $dataComplete,
        /** Дни, за которые продажи загружены, а начисления нет. */
        public int $daysWithoutAccruals,
        public DashboardMetrics $metrics,
        public array $days,
    ) {
    }
}
