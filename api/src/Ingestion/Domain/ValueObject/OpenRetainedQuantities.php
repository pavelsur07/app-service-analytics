<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\ValueObject;

use Brick\Math\BigRational;

/** Exact open gross delivery and its return tail from the future delivery. */
final readonly class OpenRetainedQuantities
{
    public string $expectedGrossNumerator;
    public string $expectedGrossDenominator;
    public string $expectedOpenReturnsNumerator;
    public string $expectedOpenReturnsDenominator;
    public string $retainedOpenNumerator;
    public string $retainedOpenDenominator;

    private function __construct(public int $eligibleOpenQuantity, BigRational $gross, BigRational $returns, BigRational $retained)
    {
        if ($eligibleOpenQuantity < 0 || $returns->isNegative() || $retained->isNegative()
            || $retained->isGreaterThan($gross) || $gross->isGreaterThan($eligibleOpenQuantity)
            || !$retained->plus($returns)->isEqualTo($gross)) {
            throw new \InvalidArgumentException('Invalid open retained quantities.');
        }
        $this->expectedGrossNumerator = (string) $gross->getNumerator();
        $this->expectedGrossDenominator = (string) $gross->getDenominator();
        $this->expectedOpenReturnsNumerator = (string) $returns->getNumerator();
        $this->expectedOpenReturnsDenominator = (string) $returns->getDenominator();
        $this->retainedOpenNumerator = (string) $retained->getNumerator();
        $this->retainedOpenDenominator = (string) $retained->getDenominator();
    }

    public static function fromProbabilities(int $eligibleOpenQuantity, GrossProbability $gross, ?ReturnProbability $cumulativeH): self
    {
        if ($eligibleOpenQuantity < 0) {
            throw new \InvalidArgumentException('Eligible open quantity cannot be negative.');
        }
        if (0 === $eligibleOpenQuantity || 0 === $gross->numerator) {
            return new self($eligibleOpenQuantity, BigRational::zero(), BigRational::zero(), BigRational::zero());
        }
        if (null === $cumulativeH) {
            throw new \InvalidArgumentException('Positive expected gross requires cumulative return probability.');
        }
        $expectedGross = BigRational::ofFraction($gross->numerator, $gross->denominator)->multipliedBy($eligibleOpenQuantity);
        $returns = $expectedGross->multipliedBy(BigRational::ofFraction($cumulativeH->numerator, $cumulativeH->denominator));

        return new self($eligibleOpenQuantity, $expectedGross, $returns, $expectedGross->minus($returns));
    }

    public static function zero(): self
    {
        return new self(0, BigRational::zero(), BigRational::zero(), BigRational::zero());
    }
}
