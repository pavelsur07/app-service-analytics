<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\ValueObject;

use Brick\Math\BigRational;

/** Exact quantity components; no display rounding or library types in the public contract. */
final readonly class DeliveredRiskQuantities
{
    public string $futureReturnsNumerator;
    public string $futureReturnsDenominator;
    public string $retainedDeliveredNumerator;
    public string $retainedDeliveredDenominator;

    private function __construct(public int $currentD, BigRational $futureReturns)
    {
        if ($currentD < 0 || $futureReturns->isNegative() || $futureReturns->isGreaterThan($currentD)) {
            throw new \InvalidArgumentException('Invalid delivered risk quantities.');
        }
        $retained = BigRational::of($currentD)->minus($futureReturns);
        $this->futureReturnsNumerator = (string) $futureReturns->getNumerator();
        $this->futureReturnsDenominator = (string) $futureReturns->getDenominator();
        $this->retainedDeliveredNumerator = (string) $retained->getNumerator();
        $this->retainedDeliveredDenominator = (string) $retained->getDenominator();
    }

    public static function fromProbability(int $currentD, ReturnProbability $probability): self
    {
        return new self($currentD, BigRational::ofFraction($probability->numerator, $probability->denominator)->multipliedBy($currentD));
    }

    public static function withoutFutureReturns(int $currentD): self
    {
        return new self($currentD, BigRational::zero());
    }
}
