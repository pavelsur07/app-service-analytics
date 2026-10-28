<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\ValueObject;

use Brick\Math\BigInteger;
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
    private BigRational $future;
    private BigRational $retained;

    private function __construct(public string $currentD, public string $eligibleOpenQuantity, BigRational $future, BigRational $retained)
    {
        if ($future->isNegative() || $future->isGreaterThan($currentD)
            || $retained->isNegative() || $retained->isGreaterThan($eligibleOpenQuantity)) {
            throw new \InvalidArgumentException('Invalid full net components.');
        }
        // Adding integer currentD preserves the reduced fractional denominator.
        $net = self::sum($retained, $future->negated());
        $this->futureReturnsNumerator = (string) $future->getNumerator();
        $this->futureReturnsDenominator = (string) $future->getDenominator();
        $this->retainedOpenNumerator = (string) $retained->getNumerator();
        $this->retainedOpenDenominator = (string) $retained->getDenominator();
        $this->expectedNetNumerator = (string) BigInteger::of($currentD)->multipliedBy($net->getDenominator())->plus($net->getNumerator());
        $this->expectedNetDenominator = (string) $net->getDenominator();
        foreach ([$this->currentD, $this->eligibleOpenQuantity, $this->futureReturnsNumerator, $this->futureReturnsDenominator,
            $this->retainedOpenNumerator, $this->retainedOpenDenominator, $this->expectedNetNumerator, $this->expectedNetDenominator] as $value) {
            if (\strlen($value) > self::MAX_DIGITS) {
                throw new \OverflowException('Exact quantity precision budget exceeded.');
            }
        }
        $this->future = $future;
        $this->retained = $retained;
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
        // Validated nonnegative components make zero bases an exact additive identity.
        if ('0' === $other->currentD && '0' === $other->eligibleOpenQuantity) {
            return $this;
        }
        if ('0' === $this->currentD && '0' === $this->eligibleOpenQuantity) {
            return $other;
        }

        return new self(
            (string) BigRational::of($this->currentD)->plus($other->currentD)->getNumerator(),
            (string) BigRational::of($this->eligibleOpenQuantity)->plus($other->eligibleOpenQuantity)->getNumerator(),
            self::sum($this->future, $other->future),
            self::sum($this->retained, $other->retained),
        );
    }

    private static function sum(BigRational $a, BigRational $b): BigRational
    {
        if ($a->isZero()) {
            return $b;
        }
        if ($b->isZero()) {
            return $a;
        }
        $aDenominator = $a->getDenominator();
        $bDenominator = $b->getDenominator();
        if ((string) $aDenominator === (string) $bDenominator) {
            return BigRational::ofFraction($a->getNumerator()->plus($b->getNumerator()), $aDenominator);
        }
        $gcd = $aDenominator->gcd($bDenominator);
        $aFactor = $bDenominator->quotient($gcd);
        $bFactor = $aDenominator->quotient($gcd);

        return BigRational::ofFraction($a->getNumerator()->multipliedBy($aFactor)->plus($b->getNumerator()->multipliedBy($bFactor)), $aDenominator->multipliedBy($aFactor));
    }
}
