<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\NetForecastQuantities;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;

final readonly class NetForecastBatch
{
    /** @param list<NetForecastSku> $skus */
    public function __construct(
        public string $companyId,
        public string $accountId,
        public ?\DateTimeImmutable $readAt,
        public ?NetTrainingContext $context,
        public array $skus,
        public ?NetForecastQuantities $quantities,
        public ?string $unavailableReason,
    ) {
    }
}
