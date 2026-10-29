<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

/** @phpstan-import-type NetPoints from SalesCohortNetMapper */
final readonly class SalesCohortNetProjection
{
    /** @param NetPoints $points
     * @param array<string, array{basis: ?string, numerator: ?int, denominator: ?int, reason: ?string}> $goalForecastProvenance */
    public function __construct(
        public array $points,
        public ?SalesCohortForecastBudget $budget,
        public ?string $goalForecastMethodVersion = null,
        public array $goalForecastProvenance = [],
    ) {
    }
}
