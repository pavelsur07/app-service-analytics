<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\NetForecastQuantities;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;
use App\Shared\Domain\ValueObject\Money;

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
        public ?Money $expectedNetRevenue,
        public ?string $moneyUnavailableReason,
        public ?NetForecastSeries $series = null,
        /** Internal publication budget continues through Facade mapping; never transport metadata. */
        public ?int $computeDeadlineNs = null,
        public ?int $allocationBaselineBytes = null,
        public ?int $outputBytesUsed = null,
        public ?int $allocationLimitBytes = null,
        public ?int $peakLimitBytes = null,
        public ?int $outputLimitBytes = null,
    ) {
    }
}
