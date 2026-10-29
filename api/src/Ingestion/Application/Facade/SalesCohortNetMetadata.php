<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

use App\Ingestion\Domain\ValueObject\ObservedReturnCurve;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;

/** Method description and the actual current read context; never a historical manifest. */
final readonly class SalesCohortNetMetadata
{
    public function __construct(
        public string $methodology = ObservedReturnCurve::MODEL_VERSION,
        public string $trainingVersion = NetTrainingContext::MODEL_VERSION,
        public int $horizonDays = ObservedReturnCurve::HORIZON,
        public ?\DateTimeImmutable $readAt = null,
        public ?string $epochId = null,
        public ?string $captureVersion = null,
        public string $coveragePolicy = NetTrainingContext::COVERAGE_POLICY,
        public string $validationStatus = 'validation_pending',
        public string $scope = 'placed_order_cohorts',
        public string $assessmentStatus = 'not_assessed',
    ) {
    }

    public static function pending(): self
    {
        return new self();
    }
}
