<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

/** Selected scopes only; no sample totals, source identifiers or private traces. */
final readonly class SalesCohortNetQuality
{
    /** @param list<string> $trainingScopes
     * @param list<string> $componentReasons */
    public function __construct(
        public ?string $sourceUnavailableReason = null,
        public ?string $sourceMoneyUnavailableReason = null,
        public array $trainingScopes = [],
        public array $componentReasons = [],
        public string $validationStatus = 'validation_pending',
        public string $assessmentStatus = 'not_assessed',
    ) {
    }

    public static function pending(): self
    {
        return new self();
    }
}
