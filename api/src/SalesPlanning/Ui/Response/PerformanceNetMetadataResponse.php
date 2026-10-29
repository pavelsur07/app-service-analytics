<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

use App\SalesPlanning\Application\SalesPlanPerformanceNetMetadata;
use OpenApi\Attributes as OA;

final readonly class PerformanceNetMetadataResponse
{
    public function __construct(
        public string $methodology,
        public string $trainingVersion,
        public int $horizonDays,
        #[OA\Property(format: 'date-time', nullable: true)] public ?string $readAt,
        public ?string $epochId,
        public ?string $captureVersion,
        public string $coveragePolicy,
        #[OA\Property(enum: ['validation_pending', 'release_approved'])] public string $validationStatus,
        public string $scope,
        #[OA\Property(enum: ['not_assessed', 'assessed'])] public string $assessmentStatus,
    ) {
    }

    public static function of(SalesPlanPerformanceNetMetadata $value): self
    {
        return new self($value->methodology, $value->trainingVersion, $value->horizonDays,
            $value->readAt?->format(\DateTimeInterface::ATOM), $value->epochId, $value->captureVersion,
            $value->coveragePolicy, $value->validationStatus, $value->scope, $value->assessmentStatus);
    }
}
