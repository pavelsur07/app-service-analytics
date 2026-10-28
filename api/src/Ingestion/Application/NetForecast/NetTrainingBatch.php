<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Infrastructure\Query\NetForecast\GrossTrainingAggregateRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;
use App\Ingestion\Infrastructure\Query\NetForecast\ReturnTrainingAggregateRow;

final readonly class NetTrainingBatch
{
    /**
     * @param list<GrossTrainingAggregateRow>  $gross
     * @param list<ReturnTrainingAggregateRow> $returns
     */
    public function __construct(
        public string $companyId,
        public string $accountId,
        public \DateTimeImmutable $readAt,
        public ?NetTrainingContext $context,
        public array $gross,
        public array $returns,
        public ?string $unavailableReason = null,
    ) {
    }
}
