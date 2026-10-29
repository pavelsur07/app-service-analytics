<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

/** Continuation of the original request budget; only two measured gross I/O intervals are excluded. */
final readonly class SalesCohortForecastBudget
{
    public function __construct(
        public int $computeDeadlineNs,
        public int $allocationBaselineBytes,
        public int $outputBytesUsed,
        public int $allocationLimitBytes,
        public int $peakLimitBytes,
        public int $outputLimitBytes,
        public int $grossSqlElapsedNs = 0,
    ) {
        foreach (get_object_vars($this) as $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException('Forecast budget scalars must be nonnegative.');
            }
        }
    }
}
