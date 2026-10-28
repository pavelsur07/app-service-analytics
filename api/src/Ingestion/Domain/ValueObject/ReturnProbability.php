<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\ValueObject;

/** Exact observed components, deliberately unreduced. */
final readonly class ReturnProbability
{
    public function __construct(public int $numerator, public int $denominator)
    {
        if ($denominator <= 0 || $numerator < 0 || $numerator > $denominator) {
            throw new \InvalidArgumentException('Invalid return probability components.');
        }
    }
}
