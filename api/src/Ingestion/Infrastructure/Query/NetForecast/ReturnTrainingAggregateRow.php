<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

final readonly class ReturnTrainingAggregateRow
{
    /** @param list<ReturnTrainingAgeRow>|null $ageProfile */
    public function __construct(
        public ?string $marketplaceSku,
        public bool $inputComplete,
        public ?string $unavailableReason,
        public ?int $sampleQuantity,
        public ?int $samplePostings,
        public ?array $ageProfile,
        public int $excludedGroups,
        public string $riskPostingMethod = 'posting_capacity_lower_bound_v1',
        public ?NetTrainingBudgetFailure $budgetFailure = null,
    ) {
    }
}
