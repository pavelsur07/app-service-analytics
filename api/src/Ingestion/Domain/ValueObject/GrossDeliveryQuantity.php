<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\ValueObject;

use Brick\Math\BigRational;

/** Exact expected gross delivery from already placed eligible open units. */
final readonly class GrossDeliveryQuantity
{
    public string $expectedGrossNumerator;
    public string $expectedGrossDenominator;

    private function __construct(public int $eligibleOpenQuantity, BigRational $expected)
    {
        if ($eligibleOpenQuantity < 0 || $expected->isNegative() || $expected->isGreaterThan($eligibleOpenQuantity)) {
            throw new \InvalidArgumentException('Invalid gross delivery quantity.');
        }
        $this->expectedGrossNumerator = (string) $expected->getNumerator();
        $this->expectedGrossDenominator = (string) $expected->getDenominator();
    }

    public static function fromProbability(int $eligibleOpenQuantity, GrossProbability $probability): self
    {
        return new self($eligibleOpenQuantity, BigRational::ofFraction($probability->numerator, $probability->denominator)->multipliedBy($eligibleOpenQuantity));
    }

    public static function zero(): self
    {
        return new self(0, BigRational::zero());
    }
}
