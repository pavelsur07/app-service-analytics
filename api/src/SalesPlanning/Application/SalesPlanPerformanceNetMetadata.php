<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\Ingestion\Application\Facade\SalesCohortNetMetadata;

final readonly class SalesPlanPerformanceNetMetadata
{
    public function __construct(
        public string $methodology,
        public string $trainingVersion,
        public int $horizonDays,
        public ?\DateTimeImmutable $readAt,
        public ?string $epochId,
        public ?string $captureVersion,
        public string $coveragePolicy,
        public string $validationStatus,
        public string $scope,
        public string $assessmentStatus,
    ) {
    }

    public static function of(SalesCohortNetMetadata $value): self
    {
        return new self($value->methodology, $value->trainingVersion, $value->horizonDays,
            $value->readAt, $value->epochId, $value->captureVersion, $value->coveragePolicy,
            $value->validationStatus, $value->scope, $value->assessmentStatus);
    }
}
