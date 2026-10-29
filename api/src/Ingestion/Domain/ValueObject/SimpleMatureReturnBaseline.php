<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\ValueObject;

use Brick\Math\BigInteger;

/** Pre-outcome B16 baseline v1, with exact remaining-horizon linear retention. */
final class SimpleMatureReturnBaseline
{
    public static function delivered(int $remaining, int $age, string $returned, string $gross): NetForecastQuantities
    {
        self::rate($returned, $gross);
        if ($remaining < 0 || $age < 0) {
            throw new \InvalidArgumentException('Invalid delivered baseline units or age.');
        }

        return NetForecastQuantities::fromComponents((string) $remaining, '0', (string) BigInteger::of($returned)->multipliedBy($remaining)->multipliedBy(max(0, 60 - $age)), (string) BigInteger::of($gross)->multipliedBy(60), '0', '1');
    }

    public static function open(int $quantity, string $grossNumerator, string $grossDenominator, string $returned, string $gross): NetForecastQuantities
    {
        self::rate($returned, $gross);
        self::rate($grossNumerator, $grossDenominator);
        if ($quantity < 0) {
            throw new \InvalidArgumentException('Invalid open baseline units.');
        }

        return NetForecastQuantities::fromComponents('0', (string) $quantity, '0', '1', (string) BigInteger::of($gross)->minus($returned)->multipliedBy($grossNumerator)->multipliedBy($quantity), (string) BigInteger::of($gross)->multipliedBy($grossDenominator));
    }

    private static function rate(string $numerator, string $denominator): void
    {
        foreach ([$numerator, $denominator] as $integer) {
            if (\strlen($integer) > 16384 || 1 !== preg_match('/^(?:0|[1-9][0-9]*)$/D', $integer)) {
                throw new \InvalidArgumentException('Invalid baseline exact integer.');
            }
        }
        if ('0' === $denominator || BigInteger::of($numerator)->isGreaterThan($denominator)) {
            throw new \InvalidArgumentException('Invalid baseline probability.');
        }
    }
}
