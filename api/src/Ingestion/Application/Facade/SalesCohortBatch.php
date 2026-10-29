<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

/** Current mutable cohort inputs, not a historical snapshot. */
final readonly class SalesCohortBatch
{
    /** @param list<SalesCohortSku> $skus */
    public function __construct(
        public array $skus,
        public \DateTimeImmutable $calculatedAt,
        public SalesCohortRequest $request,
        public string $methodology = 'ozon_order_cohort_adr046_current_buyout',
        public string $returnsCoverage = 'not_assessed',
        /** Orders coverage over the entire accumulationFrom..to range; empty batch is not assessed. */
        public string $ordersCoverage = 'not_assessed',
        public SalesCohortNetMetadata $netForecast = new SalesCohortNetMetadata(),
    ) {
    }
}
