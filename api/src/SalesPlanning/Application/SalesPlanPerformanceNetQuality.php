<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\Ingestion\Application\Facade\SalesCohortNetQuality;

final readonly class SalesPlanPerformanceNetQuality
{
    /** @param list<string> $trainingScopes
     * @param list<string> $componentReasons */
    public function __construct(
        public ?string $sourceUnavailableReason,
        public ?string $sourceMoneyUnavailableReason,
        public array $trainingScopes,
        public array $componentReasons,
        public string $validationStatus,
        public string $assessmentStatus,
    ) {
    }

    public static function of(SalesCohortNetQuality $value): self
    {
        return new self($value->sourceUnavailableReason, $value->sourceMoneyUnavailableReason,
            $value->trainingScopes, $value->componentReasons, $value->validationStatus, $value->assessmentStatus);
    }
}
