<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

use App\SalesPlanning\Application\SalesPlanPerformanceNetQuality;
use OpenApi\Attributes as OA;

final readonly class PerformanceNetQualityResponse
{
    /** @param list<string> $trainingScopes
     * @param list<string> $componentReasons */
    public function __construct(
        public ?string $sourceUnavailableReason,
        public ?string $sourceMoneyUnavailableReason,
        public array $trainingScopes,
        public array $componentReasons,
        #[OA\Property(enum: ['validation_pending', 'release_approved'])] public string $validationStatus,
        #[OA\Property(enum: ['not_assessed', 'assessed'])] public string $assessmentStatus,
    ) {
    }

    public static function of(SalesPlanPerformanceNetQuality $value): self
    {
        return new self($value->sourceUnavailableReason, $value->sourceMoneyUnavailableReason,
            $value->trainingScopes, $value->componentReasons, $value->validationStatus, $value->assessmentStatus);
    }
}
