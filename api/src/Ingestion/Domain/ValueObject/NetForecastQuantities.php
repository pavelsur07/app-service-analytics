<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\ValueObject;

use Brick\Math\BigRational;

/** Exact full net quantities, without rounding or public arithmetic-library types. */
final readonly class NetForecastQuantities
{
    private const int MAX_DIGITS = 16384;

    public string $futureReturnsNumerator;
    public string $futureReturnsDenominator;
    public string $retainedOpenNumerator;
    public string $retainedOpenDenominator;
    public string $expectedNetNumerator;
    public string $expectedNetDenominator;

    private function __construct(public string $currentD, public string $eligibleOpenQuantity, BigRational $future, BigRational $retained)
    {
        if ($future->isNegative() || $future->isGreaterThan($currentD)
            || $retained->isNegative() || $retained->isGreaterThan($eligibleOpenQuantity)) {
            throw new \InvalidArgumentException('Invalid full net components.');
        }
        $net = BigRational::of($currentD)->minus($future)->plus($retained);
        $this->futureReturnsNumerator = (string) $future->getNumerator();
        $this->futureReturnsDenominator = (string) $future->getDenominator();
        $this->retainedOpenNumerator = (string) $retained->getNumerator();
        $this->retainedOpenDenominator = (string) $retained->getDenominator();
        $this->expectedNetNumerator = (string) $net->getNumerator();
        $this->expectedNetDenominator = (string) $net->getDenominator();
        foreach ((array) $this as $value) {
            if (\strlen($value) > self::MAX_DIGITS) {
                throw new \OverflowException('Exact quantity precision budget exceeded.');
            }
        }
    }

    public static function fromComponents(string $currentD, string $eligibleOpenQuantity, string $futureReturnsNumerator, string $futureReturnsDenominator, string $retainedOpenNumerator, string $retainedOpenDenominator): self
    {
        foreach ([$currentD, $eligibleOpenQuantity, $futureReturnsNumerator, $futureReturnsDenominator, $retainedOpenNumerator, $retainedOpenDenominator] as $value) {
            if (\strlen($value) > self::MAX_DIGITS || 1 !== preg_match('/^(?:0|[1-9][0-9]*)$/D', $value)) {
                throw new \InvalidArgumentException('Quantity components must be bounded canonical nonnegative integers.');
            }
        }
        if ('0' === $futureReturnsDenominator || '0' === $retainedOpenDenominator) {
            throw new \InvalidArgumentException('Quantity denominators must be positive.');
        }

        return new self($currentD, $eligibleOpenQuantity, BigRational::ofFraction($futureReturnsNumerator, $futureReturnsDenominator), BigRational::ofFraction($retainedOpenNumerator, $retainedOpenDenominator));
    }

    public static function zero(): self
    {
        return new self('0', '0', BigRational::zero(), BigRational::zero());
    }

    public function plus(self $other): self
    {
        return new self(
            (string) BigRational::of($this->currentD)->plus($other->currentD)->getNumerator(),
            (string) BigRational::of($this->eligibleOpenQuantity)->plus($other->eligibleOpenQuantity)->getNumerator(),
            BigRational::ofFraction($this->futureReturnsNumerator, $this->futureReturnsDenominator)->plus(BigRational::ofFraction($other->futureReturnsNumerator, $other->futureReturnsDenominator)),
            BigRational::ofFraction($this->retainedOpenNumerator, $this->retainedOpenDenominator)->plus(BigRational::ofFraction($other->retainedOpenNumerator, $other->retainedOpenDenominator)),
        );
    }
}
