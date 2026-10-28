<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\ValueObject;

/** Exact gross G=D+R probability components, deliberately unreduced. */
final readonly class GrossProbability
{
    public function __construct(public int $numerator, public int $denominator)
    {
        if ($denominator <= 0 || $numerator < 0 || $numerator > $denominator) {
            throw new \InvalidArgumentException('Invalid gross probability components.');
        }
    }
}
