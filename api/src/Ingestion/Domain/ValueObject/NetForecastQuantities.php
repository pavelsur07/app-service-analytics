<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\ValueObject;

use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

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

    private function __construct(public string $currentD, public string $eligibleOpenQuantity, private BigInteger $futureN, private BigInteger $futureD, private BigInteger $retainedN, private BigInteger $retainedD)
    {
        if ($futureN->isNegative() || $futureN->isGreaterThan($futureD->multipliedBy($currentD))
            || $retainedN->isNegative() || $retainedN->isGreaterThan($retainedD->multipliedBy($eligibleOpenQuantity))) {
            throw new \InvalidArgumentException('Invalid full net components.');
        }
        // Adding integer currentD preserves the reduced fractional denominator.
        [$netN, $netD] = self::sum($retainedN, $retainedD, $futureN->negated(), $futureD);
        $this->futureReturnsNumerator = (string) $futureN;
        $this->futureReturnsDenominator = (string) $futureD;
        $this->retainedOpenNumerator = (string) $retainedN;
        $this->retainedOpenDenominator = (string) $retainedD;
        $this->expectedNetNumerator = (string) BigInteger::of($currentD)->multipliedBy($netD)->plus($netN);
        $this->expectedNetDenominator = (string) $netD;
        foreach ([$this->currentD, $this->eligibleOpenQuantity, $this->futureReturnsNumerator, $this->futureReturnsDenominator,
            $this->retainedOpenNumerator, $this->retainedOpenDenominator, $this->expectedNetNumerator, $this->expectedNetDenominator] as $value) {
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

        $future = BigRational::ofFraction($futureReturnsNumerator, $futureReturnsDenominator);
        $retained = BigRational::ofFraction($retainedOpenNumerator, $retainedOpenDenominator);

        return new self($currentD, $eligibleOpenQuantity, $future->getNumerator(), $future->getDenominator(), $retained->getNumerator(), $retained->getDenominator());
    }

    public static function zero(): self
    {
        return new self('0', '0', BigInteger::zero(), BigInteger::one(), BigInteger::zero(), BigInteger::one());
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

        [$futureN, $futureD] = self::sum($this->futureN, $this->futureD, $other->futureN, $other->futureD);
        [$retainedN, $retainedD] = self::sum($this->retainedN, $this->retainedD, $other->retainedN, $other->retainedD);

        return new self(
            (string) BigInteger::of($this->currentD)->plus($other->currentD),
            (string) BigInteger::of($this->eligibleOpenQuantity)->plus($other->eligibleOpenQuantity),
            $futureN, $futureD, $retainedN, $retainedD,
        );
    }

    /**
     * Sufficient proof for regrouping any subset without a stored-field precision refusal.
     * P, the product of all non-unit component denominators, is <10^S (or 1 for S=0).
     * Every subset denominator divides P; future<=D, retained<=E and net<=D+E.
     * With n terms and L base digits, D+E < 2*n*10^L < 10^(L+digits(n)+1).
     * Thus S+L+digits(n)+1 bounds all eight fields, including the unreduced net numerator.
     * False requires chronological addition; it does not establish an overflow.
     *
     * @param iterable<self> $quantities
     */
    public static function canSumWithoutPrecisionOverflow(iterable $quantities): bool
    {
        $count = $baseDigits = $denominatorDigits = 0;
        foreach ($quantities as $quantity) {
            ++$count;
            $baseDigits = max($baseDigits, \strlen($quantity->currentD), \strlen($quantity->eligibleOpenQuantity));
            foreach ([$quantity->futureReturnsDenominator, $quantity->retainedOpenDenominator] as $denominator) {
                if ('1' !== $denominator) {
                    $denominatorDigits += \strlen($denominator);
                }
            }
        }

        return 0 === $count || $denominatorDigits + $baseDigits + \strlen((string) $count) + 1 <= self::MAX_DIGITS;
    }

    public function expectedNetDecimal(): string
    {
        // The scope fraction is already validated; avoid reducing it again just to divide.
        return (string) BigInteger::of($this->expectedNetNumerator)->toBigDecimal()->dividedBy($this->expectedNetDenominator, 3, RoundingMode::HalfUp);
    }

    /**
     * Reduced inputs can share a remaining factor only through their denominator GCD.
     * Same exact kernel as NetForecastRevenue::plus; constructor precision checks still
     * run after every chronological addition, including an intermediate cancellation.
     *
     * @return array{BigInteger, BigInteger}
     */
    private static function sum(BigInteger $aN, BigInteger $aD, BigInteger $bN, BigInteger $bD): array
    {
        if ($aN->isZero()) {
            return [$bN, $bD];
        }
        if ($bN->isZero()) {
            return [$aN, $aD];
        }
        $gcd = $aD->gcd($bD);
        $aFactor = $bD->quotient($gcd);
        $bFactor = $aD->quotient($gcd);
        $numerator = $aN->multipliedBy($aFactor)->plus($bN->multipliedBy($bFactor));
        $reduction = $numerator->gcd($gcd);

        return [$numerator->quotient($reduction), $aD->multipliedBy($aFactor)->quotient($reduction)];
    }
}
