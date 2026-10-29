<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\ValueObject;

use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/** Bounded Brick arithmetic for private evaluation; only canonical strings leave this class. */
final readonly class NetQualityExact
{
    private function __construct(private BigRational $value)
    {
        self::bounded($value->getNumerator());
        self::bounded($value->getDenominator());
    }

    public static function fraction(string $numerator, string $denominator = '1'): self
    {
        if (\strlen(ltrim($numerator, '-')) > 16384 || \strlen($denominator) > 16384 || 1 !== preg_match('/^(?:0|-?[1-9][0-9]*)$/D', $numerator) || 1 !== preg_match('/^[1-9][0-9]*$/D', $denominator)) {
            throw new \InvalidArgumentException('Invalid exact quality fraction.');
        }

        return new self(BigRational::ofFraction($numerator, $denominator));
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys);
        if (['denominator', 'numerator'] !== $keys || !\is_string($value['numerator']) || !\is_string($value['denominator'])) {
            throw new \InvalidArgumentException('Invalid exact fraction fields.');
        }
        $n = $value['numerator'];
        $d = $value['denominator'];
        $result = self::fraction($n, $d);
        if ($result->toArray() !== ['numerator' => $n, 'denominator' => $d]) {
            throw new \InvalidArgumentException('Quality fractions must be reduced.');
        }

        return $result;
    }

    public function plus(self $other): self
    {
        $a = $this->value;
        $b = $other->value;
        if ($a->isZero()) {
            return $other;
        }
        if ($b->isZero()) {
            return $this;
        }
        if ($a->getDenominator()->isEqualTo($b->getDenominator())) {
            $n = $a->getNumerator()->plus($b->getNumerator());
            self::bounded($n);

            return new self(BigRational::ofFraction($n, $a->getDenominator()));
        }
        $left = self::product($a->getNumerator(), $b->getDenominator());
        $right = self::product($b->getNumerator(), $a->getDenominator());
        $n = $left->plus($right);
        self::bounded($n);

        return new self(BigRational::ofFraction($n, self::product($a->getDenominator(), $b->getDenominator())));
    }

    public function minus(self $other): self
    {
        return $this->plus(new self($other->value->negated()));
    }

    public function times(self $other): self
    {
        return new self(BigRational::ofFraction(self::product($this->value->getNumerator(), $other->value->getNumerator()), self::product($this->value->getDenominator(), $other->value->getDenominator())));
    }

    public function dividedBy(self $other): self
    {
        if ($other->isZero()) {
            throw new \InvalidArgumentException('Zero quality divisor.');
        }

        return new self(BigRational::ofFraction(self::product($this->value->getNumerator(), $other->value->getDenominator()), self::product($this->value->getDenominator(), $other->value->getNumerator())));
    }

    public function abs(): self
    {
        return new self($this->value->abs());
    }

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    public function isNegative(): bool
    {
        return $this->value->isNegative();
    }

    public function compare(self $other): int
    {
        if ($this->value->getDenominator()->isEqualTo($other->value->getDenominator())) {
            return $this->value->getNumerator()->compareTo($other->value->getNumerator());
        }

        return self::product($this->value->getNumerator(), $other->value->getDenominator())->compareTo(self::product($other->value->getNumerator(), $this->value->getDenominator()));
    }

    public function roundedMinor(): self
    {
        return self::fraction((string) $this->value->toScale(0, RoundingMode::HalfUp));
    }

    public function display(): string
    {
        self::product($this->value->getNumerator(), BigInteger::of('1000000'));

        return (string) $this->value->toScale(6, RoundingMode::HalfUp);
    }

    /** @return array{numerator: string, denominator: string} */
    public function toArray(): array
    {
        return ['numerator' => (string) $this->value->getNumerator(), 'denominator' => (string) $this->value->getDenominator()];
    }

    private static function bounded(BigInteger $value): void
    {
        if (\strlen(ltrim((string) $value, '-')) > 16384) {
            throw new \OverflowException('Quality exact intermediate exceeds precision limit.');
        }
    }

    private static function product(BigInteger $a, BigInteger $b): BigInteger
    {
        if ($a->isZero() || $b->isZero()) {
            return BigInteger::zero();
        }
        if (!\in_array((string) $a, ['1', '-1'], true) && !\in_array((string) $b, ['1', '-1'], true) && \strlen(ltrim((string) $a, '-')) + \strlen(ltrim((string) $b, '-')) > 16384) {
            throw new \OverflowException('Quality exact multiplication exceeds precision budget.');
        }
        $result = $a->multipliedBy($b);
        self::bounded($result);

        return $result;
    }
}
