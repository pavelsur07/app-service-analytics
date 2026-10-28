<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

final readonly class NetTargetBatch
{
    /** @param list<NetTargetSkuRow> $skus */
    public function __construct(
        public NetTrainingContext $context,
        public array $skus,
        public ?string $unavailableReason = null,
        public ?NetTrainingBudgetFailure $budgetFailure = null,
    ) {
    }
}
