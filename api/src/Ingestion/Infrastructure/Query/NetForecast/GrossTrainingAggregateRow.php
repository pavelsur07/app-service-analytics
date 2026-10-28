<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

/** Sufficient exact components; this DTO does not calculate a probability. */
final readonly class GrossTrainingAggregateRow
{
    public function __construct(
        public ?string $marketplaceSku,
        public bool $inputComplete,
        public ?string $unavailableReason,
        public ?int $sampleQuantity,
        public ?int $samplePostings,
        public ?int $grossQuantity,
        public ?int $t1Quantity,
        public ?int $t2Quantity,
        public ?int $pQuantity,
        public ?int $postHandoverPostings,
        public ?int $p95Seconds,
        public int $excludedGroups,
        public int $excludedDays,
        public ?NetTrainingBudgetFailure $budgetFailure = null,
    ) {
    }
}
