<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Domain\ValueObject\PreHandoverGrossSample;
use PHPUnit\Framework\TestCase;

final class PreHandoverGrossSampleTest extends TestCase
{
    public function testT1StaysInPreHandoverDenominatorAndGrossIsAlreadyDPlusR(): void
    {
        $probability = new PreHandoverGrossSample(200, 30, 100, 100, 0, 0)->probability();
        self::assertNotNull($probability);
        self::assertSame(100, $probability->numerator);
        self::assertSame(200, $probability->denominator);
    }

    public function testInclusiveMinimumAndGenuineZeroAndOneAreDifferentFromUnknownSample(): void
    {
        foreach ([[100, 30, 0, 100, 0], [100, 30, 100, 0, 100]] as [$quantity, $postings, $gross, $t1, $expected]) {
            $probability = new PreHandoverGrossSample($quantity, $postings, $gross, $t1, 0, 0)->probability();
            self::assertNotNull($probability);
            self::assertSame($expected, $probability->numerator);
            self::assertSame(100, $probability->denominator);
        }
        foreach ([[0, 0], [99, 30], [100, 29]] as [$quantity, $postings]) {
            self::assertNull(new PreHandoverGrossSample($quantity, $postings, 0, $quantity, 0, 0)->probability());
        }
    }

    public function testLargeConservationNeverAddsMachineIntegers(): void
    {
        $probability = new PreHandoverGrossSample(\PHP_INT_MAX, 30, \PHP_INT_MAX - 3, 1, 1, 1)->probability();
        self::assertNotNull($probability);
        self::assertSame(\PHP_INT_MAX, $probability->denominator);
        self::assertSame(\PHP_INT_MAX - 3, $probability->numerator);
    }

    public function testRejectsNegativeImpossibleCountsAndOverflowingSumsWithoutClipping(): void
    {
        foreach ([[-1, 0, 0, 0, 0, 0], [100, -1, 100, 0, 0, 0], [100, 101, 100, 0, 0, 0],
            [100, 0, 100, 0, 0, 0], [0, 1, 0, 0, 0, 0], [100, 30, -1, 101, 0, 0],
            [100, 30, 99, 0, 0, 0], [100, 30, 100, 1, 0, 0],
            [\PHP_INT_MAX, 30, \PHP_INT_MAX, \PHP_INT_MAX, 0, 0],
            [\PHP_INT_MAX, 30, \PHP_INT_MAX - 1, 0, 1, \PHP_INT_MAX]] as $values) {
            try {
                new PreHandoverGrossSample(...$values);
                self::fail('Impossible sample accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
