<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\ReturnProbabilityAssessment;

/** One whole training scope and its age-specific availability, without mixed samples. */
final readonly class DeliveredRiskAttempt
{
    public function __construct(public ScopedReturnCurve $source, public ReturnProbabilityAssessment $assessment)
    {
    }
}
