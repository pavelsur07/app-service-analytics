<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

/** Internal exact components; no source graph or arithmetic-library objects cross modules. */
final readonly class SalesCohortGoalForecast
{
    public function __construct(
        public ?string $retainedNumerator,
        public ?string $retainedDenominator,
        public ?string $retainedUnavailableReason,
        public ?string $futureT1Numerator,
        public ?string $futureT1Denominator,
        public ?string $futureT1UnavailableReason,
    ) {
        self::assertPair($retainedNumerator, $retainedDenominator, $retainedUnavailableReason);
        self::assertPair($futureT1Numerator, $futureT1Denominator, $futureT1UnavailableReason);
    }

    private static function assertPair(?string $n, ?string $d, ?string $reason): void
    {
        if (null !== $reason && '' !== trim($reason) && null === $n && null === $d) {
            return;
        }
        if (null !== $reason || null === $n || null === $d || '0' === $d || \strlen($n) > 16384 || \strlen($d) > 16384
            || 1 !== preg_match('/^(?:0|[1-9][0-9]*)$/D', $n) || 1 !== preg_match('/^[1-9][0-9]*$/D', $d)) {
            throw new \InvalidArgumentException('Goal components require bounded canonical complete pairs or an explicit reason.');
        }
    }
}
