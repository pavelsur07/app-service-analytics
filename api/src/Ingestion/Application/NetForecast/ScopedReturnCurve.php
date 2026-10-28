<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\ObservedReturnCurve;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;
use App\Ingestion\Infrastructure\Query\NetForecast\ReturnTrainingAggregateRow;

/** Current RR evidence only; no historical visibility or quality claim. */
final readonly class ScopedReturnCurve
{
    public function __construct(
        public string $companyId,
        public string $accountId,
        public \DateTimeImmutable $readAt,
        public ?NetTrainingContext $context,
        public ?string $marketplaceSku,
        public ?ReturnTrainingAggregateRow $source,
        public ?ObservedReturnCurve $curve,
        public ?string $unavailableReason,
    ) {
    }
}
