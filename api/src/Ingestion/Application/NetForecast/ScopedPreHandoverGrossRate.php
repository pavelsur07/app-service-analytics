<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\GrossProbability;
use App\Ingestion\Infrastructure\Query\NetForecast\GrossTrainingAggregateRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;

/** One whole current-RR row and its availability, preserving raw diagnostics. */
final readonly class ScopedPreHandoverGrossRate
{
    public function __construct(
        public string $companyId,
        public string $accountId,
        public \DateTimeImmutable $readAt,
        public ?NetTrainingContext $context,
        public ?string $marketplaceSku,
        public ?GrossTrainingAggregateRow $source,
        public ?GrossProbability $probability,
        public ?string $unavailableReason,
    ) {
    }
}
