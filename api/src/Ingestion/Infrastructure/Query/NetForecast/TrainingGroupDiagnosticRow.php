<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

final readonly class TrainingGroupDiagnosticRow
{
    public function __construct(
        public ?string $dateKey,
        public ?string $orderNumber,
        public ?string $orderKey,
        public ?string $marketplaceSku,
        public bool $orderMissing,
        public bool $eligible,
        public ?string $exclusionReason,
        public bool $inputComplete,
    ) {
    }
}
