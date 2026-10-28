<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

/** Operational limits may be reduced by DI, never enlarged by a request. */
final readonly class NetTargetLimits
{
    public function __construct(
        public int $groups = 20000,
        public int $sourceKeys = 50000,
        public int $revisions = 400000,
        public int $statuses = 200000,
        public int $receipts = 50000,
        public int $groupsPerSkuDay = 200,
        public int $sourcesPerGroup = 128,
        public int $revisionsPerSource = 128,
        public int $statusesPerGroup = 512,
        public int $outputBytes = 33554432,
        public int $phpAllocationBytes = 50331648,
        public int $phpPeakBytes = 100663296,
        public float $computeSeconds = 5,
    ) {
        foreach (['groups' => 20000, 'sourceKeys' => 50000, 'revisions' => 400000, 'statuses' => 200000, 'receipts' => 50000, 'groupsPerSkuDay' => 200, 'sourcesPerGroup' => 128, 'revisionsPerSource' => 128, 'statusesPerGroup' => 512, 'outputBytes' => 33554432, 'phpAllocationBytes' => 50331648, 'phpPeakBytes' => 100663296] as $name => $maximum) {
            if ($this->$name < 1 || $this->$name > $maximum) {
                throw new \InvalidArgumentException('Target limits must be positive and cannot exceed operational caps.');
            }
        }
        if (!is_finite($computeSeconds) || $computeSeconds <= 0 || $computeSeconds > 5) {
            throw new \InvalidArgumentException('Invalid target compute deadline.');
        }
    }
}
