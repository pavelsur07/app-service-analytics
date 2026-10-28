<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\ValueObject;

final readonly class ObservedReturnCurve
{
    public const string MODEL_VERSION = 'observed_retention_h60_v1';
    public const int HORIZON = 60;

    /** @param list<ReturnCurvePoint> $points */
    public function __construct(public int $sampleQuantity, public int $samplePostings, public array $points)
    {
        if ($sampleQuantity < 0 || $samplePostings < 0 || $samplePostings > $sampleQuantity
            || (0 === $samplePostings && 0 !== $sampleQuantity)
            || !array_is_list($points) || self::HORIZON + 1 !== \count($points)) {
            throw new \InvalidArgumentException('Invalid return curve sample or age profile.');
        }
        $previousReturned = 0;
        $previousBound = $samplePostings;
        foreach ($points as $age => $point) {
            if ($point->age !== $age || $point->returnedQuantity < $previousReturned || $point->returnedQuantity > $sampleQuantity
                || $point->atRiskQuantity !== $sampleQuantity - $point->returnedQuantity
                || $point->atRiskPostingsLowerBound > min($previousBound, $point->atRiskQuantity)
                || (0 === $point->atRiskPostingsLowerBound && $point->atRiskQuantity > 0)
                || (0 === $age && (0 !== $point->returnedQuantity || $point->atRiskPostingsLowerBound !== $samplePostings))) {
                throw new \InvalidArgumentException('Inconsistent return curve age profile.');
            }
            $previousReturned = $point->returnedQuantity;
            $previousBound = $point->atRiskPostingsLowerBound;
        }
    }

    public function cumulativeAt(int $age): ReturnProbabilityAssessment
    {
        $this->assertAge($age, self::HORIZON);
        if (!$this->hasSample()) {
            return ReturnProbabilityAssessment::unavailable('insufficient_sample');
        }

        return ReturnProbabilityAssessment::available(new ReturnProbability($this->points[$age]->returnedQuantity, $this->sampleQuantity));
    }

    public function remainingAt(int $age): ReturnProbabilityAssessment
    {
        $this->assertAge($age, self::HORIZON - 1);
        if (!$this->hasSample()) {
            return ReturnProbabilityAssessment::unavailable('insufficient_sample');
        }
        $point = $this->points[$age];
        if ($point->atRiskQuantity < 30 || $point->atRiskPostingsLowerBound < 10) {
            return ReturnProbabilityAssessment::unavailable('insufficient_risk_set');
        }

        return ReturnProbabilityAssessment::available(new ReturnProbability($this->points[self::HORIZON]->returnedQuantity - $point->returnedQuantity, $point->atRiskQuantity));
    }

    private function hasSample(): bool
    {
        return $this->sampleQuantity >= 100 && $this->samplePostings >= 30;
    }

    private function assertAge(int $age, int $maximum): void
    {
        if ($age < 0 || $age > $maximum) {
            throw new \InvalidArgumentException('Age outside observed return curve.');
        }
    }
}
