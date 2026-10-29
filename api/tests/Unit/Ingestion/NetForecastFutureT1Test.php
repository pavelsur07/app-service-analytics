<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Domain\ValueObject\NetForecastFutureT1;
use PHPUnit\Framework\TestCase;

final class NetForecastFutureT1Test extends TestCase
{
    public function testProbabilityUsesOnlyFuturePreAndExactSum(): void
    {
        $zero = NetForecastFutureT1::zero();
        self::assertSame($zero, $zero->plus(NetForecastFutureT1::zero()), 'Proven zero addition preserves the existing scope identity.');
        $ten = NetForecastFutureT1::fromProbability(100, 10, 100);
        self::assertSame('10', $ten->numerator());
        self::assertSame('1', $ten->denominator());
        $third = NetForecastFutureT1::fromProbability(1, 1, 3);
        self::assertSame('1', $third->plus($third)->plus($third)->numerator());
        self::assertSame('1', $third->plus($third)->plus($third)->denominator());
        self::assertSame($ten, $ten->plus(NetForecastFutureT1::zero()));
        self::assertSame($ten, NetForecastFutureT1::zero()->plus($ten));
        self::assertSame(134, $ten->storageBytes());
        self::assertSame([], get_object_vars($ten) === ['unavailableReason' => null] ? [] : ['private pairs leaked']);
    }

    public function testUnavailableIsStickyAndNotZero(): void
    {
        $bad = NetForecastFutureT1::unavailable('unknown_outcome');
        self::assertNull($bad->numerator());
        self::assertNull($bad->denominator());
        self::assertSame($bad, $bad->plus(NetForecastFutureT1::zero()));
        self::assertSame($bad, NetForecastFutureT1::zero()->plus($bad));
        self::assertSame($bad, $bad->plus(NetForecastFutureT1::unavailable('other')));
    }

    public function testInvalidProbabilityCannotBecomeZeroOrNegative(): void
    {
        foreach ([[-1, 0, 1], [1, -1, 1], [1, 2, 1], [1, 0, 0]] as [$pre, $n, $d]) {
            try {
                NetForecastFutureT1::fromProbability($pre, $n, $d);
                self::fail('Invalid probability accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
