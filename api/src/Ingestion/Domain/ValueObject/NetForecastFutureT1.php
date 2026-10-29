<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\ValueObject;

use Brick\Math\BigRational;

/** Private exact remaining PRE cancellations; independent of return-model availability. */
final readonly class NetForecastFutureT1
{
    public const string METHOD_VERSION = 'pre_t1_gross_first_valid_v1';

    private function __construct(private ?string $n, private ?string $d, public ?string $unavailableReason)
    {
        if (\strlen($n ?? '') > 16384 || \strlen($d ?? '') > 16384) {
            throw new \OverflowException('Future T1 precision budget exceeded.');
        }
    }

    public static function zero(): self
    {
        return new self('0', '1', null);
    }

    public static function unavailable(string $reason): self
    {
        if ('' === trim($reason)) {
            throw new \InvalidArgumentException('Unavailable future T1 requires a reason.');
        }

        return new self(null, null, $reason);
    }

    public static function fromProbability(int $eligibleOpenPre, int $t1Numerator, int $denominator): self
    {
        if ($eligibleOpenPre < 0 || $t1Numerator < 0 || $denominator < 1 || $t1Numerator > $denominator) {
            throw new \InvalidArgumentException('Invalid PRE cancellation probability.');
        }
        $value = BigRational::ofFraction($t1Numerator, $denominator)->multipliedBy($eligibleOpenPre);

        return new self((string) $value->getNumerator(), (string) $value->getDenominator(), null);
    }

    public function plus(self $other): self
    {
        if (null !== $this->unavailableReason) {
            return $this;
        }
        if (null !== $other->unavailableReason) {
            return $other;
        }
        if ('0' === $other->n) {
            return $this;
        }
        if ('0' === $this->n) {
            return $other;
        }
        \assert(null !== $this->n && null !== $this->d && null !== $other->n && null !== $other->d);
        $value = BigRational::ofFraction($this->n, $this->d)->plus(BigRational::ofFraction($other->n, $other->d));

        return new self((string) $value->getNumerator(), (string) $value->getDenominator(), null);
    }

    public function numerator(): ?string
    {
        return $this->n;
    }

    public function denominator(): ?string
    {
        return $this->d;
    }

    public function storageBytes(): int
    {
        return 128 + 2 * (\strlen($this->n ?? '') + \strlen($this->d ?? '')) + 6 * \strlen($this->unavailableReason ?? '');
    }
}
