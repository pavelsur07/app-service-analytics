<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

/** Current mutable cohort inputs, not a historical snapshot. */
final readonly class SalesCohortBatch
{
    /** @param list<SalesCohortSku> $skus
     * @param array<string, array{basis: ?string, numerator: ?int, denominator: ?int, reason: ?string}> $goalForecastProvenance */
    public function __construct(
        public array $skus,
        public \DateTimeImmutable $calculatedAt,
        public SalesCohortRequest $request,
        public string $methodology = 'ozon_order_cohort_adr046_current_buyout',
        public string $returnsCoverage = 'not_assessed',
        /** Orders coverage over the entire accumulationFrom..to range; empty batch is not assessed. */
        public string $ordersCoverage = 'not_assessed',
        public SalesCohortNetMetadata $netForecast = new SalesCohortNetMetadata(),
        public ?SalesCohortForecastBudget $forecastBudget = null,
        private ?string $goalForecastMethodVersion = null,
        private array $goalForecastProvenance = [],
    ) {
    }

    public function goalForecastMethodVersion(): ?string
    {
        return $this->goalForecastMethodVersion;
    }

    /** @return array<string, array{basis: ?string, numerator: ?int, denominator: ?int, reason: ?string}> */
    public function goalForecastProvenance(): array
    {
        return $this->goalForecastProvenance;
    }
}
