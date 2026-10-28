<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

/** Operational input budgets, never statistical sample minimums. */
final readonly class NetTrainingLimits
{
    public function __construct(
        public int $groups = 20000,
        public int $salesKeys = 50000,
        public int $returnKeys = 50000,
        public int $revisions = 400000,
        public int $statuses = 200000,
        public int $receipts = 50000,
        public int $keysPerGroup = 128,
        public int $revisionsPerSource = 128,
        public int $statusesPerGroup = 512,
    ) {
        foreach ([$groups, $salesKeys, $returnKeys, $revisions, $statuses, $receipts, $keysPerGroup, $revisionsPerSource, $statusesPerGroup] as $limit) {
            if ($limit < 1 || $limit > 1000000) {
                throw new \InvalidArgumentException('Training input limits must be finite positive integers.');
            }
        }
    }
}
