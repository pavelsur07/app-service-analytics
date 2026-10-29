<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\ValueObject;

use App\Shared\Domain\ValueObject\Money;
use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/** Exact expected minor units; only the published scope is rounded. */
final readonly class NetForecastRevenue
{
    private const int MAX_DIGITS = 16384;

    private function __construct(private ?BigInteger $numerator, private ?BigInteger $denominator, private ?string $currency, private ?string $failure)
    {
    }

    public static function empty(): self
    {
        return new self(null, null, null, null);
    }

    public static function unavailable(string $reason): self
    {
        return new self(null, null, null, $reason);
    }

    public static function fromSource(?string $unitPriceMinor, ?string $currency, NetForecastQuantities $quantities): self
    {
        if (null === $unitPriceMinor) {
            return self::unavailable('money_price_missing');
        }
        if (null === $currency) {
            return self::unavailable('money_currency_missing');
        }
        if (\strlen($unitPriceMinor) > 20) {
            $digits = str_starts_with($unitPriceMinor, '-') ? substr($unitPriceMinor, 1) : $unitPriceMinor;

            return self::unavailable('0' !== $digits[0] && ctype_digit($digits) ? 'money_overflow' : 'money_input_invalid');
        }
        if (1 !== preg_match('/^(?:0|-?[1-9][0-9]*)$/D', $unitPriceMinor)) {
            return self::unavailable('money_input_invalid');
        }
        if (!self::fitsNative($unitPriceMinor)) {
            return self::unavailable('money_overflow');
        }
        try {
            Money::ofDecimalString('0', $currency);
            $price = Money::ofMinor((int) $unitPriceMinor, $currency);
        } catch (\InvalidArgumentException) {
            return self::unavailable('money_input_invalid');
        }
        if (!self::productFits($unitPriceMinor, $quantities->expectedNetNumerator)) {
            return self::unavailable('money_precision_exceeded');
        }
        $amount = BigRational::ofFraction($quantities->expectedNetNumerator, $quantities->expectedNetDenominator)
            ->multipliedBy($price->minorAmount());

        return self::bounded($amount, $price->currency());
    }

    public function plus(self $other): self
    {
        if (null !== $this->failure) {
            return $this;
        }
        if (null !== $other->failure) {
            return $other;
        }
        if (null === $this->numerator) {
            return $other;
        }
        if (null === $other->numerator) {
            return $this;
        }
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException('Cannot sum expected revenue in different currencies.');
        }
        if ($this->numerator->isZero()) {
            return $other;
        }
        if ($other->numerator->isZero()) {
            return $this;
        }
        $aDenominator = $this->denominator;
        $bDenominator = $other->denominator;
        \assert(null !== $aDenominator && null !== $bDenominator && null !== $this->currency);
        if ((string) $aDenominator !== (string) $bDenominator && !self::productFits((string) $aDenominator, (string) $bDenominator)) {
            return self::unavailable('money_precision_exceeded');
        }
        $gcd = $aDenominator->gcd($bDenominator);
        $aFactor = $bDenominator->quotient($gcd);
        $bFactor = $aDenominator->quotient($gcd);
        if (!self::productFits((string) $this->numerator, (string) $aFactor) || !self::productFits((string) $other->numerator, (string) $bFactor) || !self::productFits((string) $aDenominator, (string) $aFactor)) {
            return self::unavailable('money_precision_exceeded');
        }
        $numerator = $this->numerator->multipliedBy($aFactor)->plus($other->numerator->multipliedBy($bFactor));
        // Reduced inputs can share a remaining factor only through the denominator GCD.
        $reduction = $numerator->gcd($gcd);
        $numerator = $numerator->quotient($reduction);
        $denominator = $aDenominator->multipliedBy($aFactor)->quotient($reduction);
        if (\strlen(ltrim((string) $numerator, '-')) > self::MAX_DIGITS || \strlen((string) $denominator) > self::MAX_DIGITS) {
            return self::unavailable('money_precision_exceeded');
        }

        return new self($numerator, $denominator, $this->currency, null);
    }

    public function unavailableReason(): ?string
    {
        return $this->failure ?? (null === $this->currency ? 'money_currency_missing' : null);
    }

    public function storageBytes(): int
    {
        // Include both BigInteger backing strings, objects and private fields.
        return 512 + 2 * (\strlen((string) $this->numerator) + \strlen((string) $this->denominator))
            + 6 * (\strlen($this->currency ?? '') + \strlen($this->failure ?? ''));
    }

    public function toMoney(): ?Money
    {
        if (null === $this->numerator || null === $this->denominator || null === $this->currency || null !== $this->failure) {
            return null;
        }
        $minor = (string) $this->numerator->dividedBy($this->denominator, RoundingMode::HalfUp);
        if (!self::fitsNative($minor)) {
            throw new \OverflowException('Expected revenue does not fit native Money.');
        }

        return Money::ofMinor((int) $minor, $this->currency);
    }

    /** @return array{0: ?Money, 1: ?string} */
    public function toMoneyOutcome(): array
    {
        try {
            return [$this->toMoney(), $this->unavailableReason()];
        } catch (\OverflowException) {
            return [null, 'money_overflow'];
        }
    }

    private static function bounded(BigRational $amount, string $currency): self
    {
        if (\strlen(ltrim((string) $amount->getNumerator(), '-')) > self::MAX_DIGITS || \strlen((string) $amount->getDenominator()) > self::MAX_DIGITS) {
            return self::unavailable('money_precision_exceeded');
        }

        return new self($amount->getNumerator(), $amount->getDenominator(), $currency, null);
    }

    private static function productFits(string $a, string $b): bool
    {
        return '0' === $a || '0' === $b || '1' === ltrim($a, '-') || '1' === ltrim($b, '-') || \strlen(ltrim($a, '-')) + \strlen(ltrim($b, '-')) <= self::MAX_DIGITS;
    }

    private static function fitsNative(string $value): bool
    {
        $negative = str_starts_with($value, '-');
        $digits = $negative ? substr($value, 1) : $value;
        $limit = $negative ? substr((string) \PHP_INT_MIN, 1) : (string) \PHP_INT_MAX;

        return \strlen($digits) < \strlen($limit) || (\strlen($digits) === \strlen($limit) && strcmp($digits, $limit) <= 0);
    }
}
