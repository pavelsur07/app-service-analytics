<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Domain\ValueObject\GrossDeliveryQuantity;
use App\Ingestion\Domain\ValueObject\GrossProbability;
use PHPUnit\Framework\TestCase;

final class GrossDeliveryQuantityTest extends TestCase
{
    public function testExactGrossQuantityHasNoRoundingOrOverflow(): void
    {
        foreach ([[100, 100, 200, '50', '1'], [1, 1, 3, '1', '3'], [2, 1, 3, '2', '3'],
            [\PHP_INT_MAX, 0, 100, '0', '1'], [\PHP_INT_MAX, 100, 100, '9223372036854775807', '1']] as [$eligible, $numerator, $denominator, $expected, $divisor]) {
            $quantity = GrossDeliveryQuantity::fromProbability($eligible, new GrossProbability($numerator, $denominator));
            self::assertSame($eligible, $quantity->eligibleOpenQuantity);
            self::assertSame($expected, $quantity->expectedGrossNumerator);
            self::assertSame($divisor, $quantity->expectedGrossDenominator);
        }
        $large = GrossDeliveryQuantity::fromProbability(\PHP_INT_MAX - 2, new GrossProbability(\PHP_INT_MAX - 1, \PHP_INT_MAX));
        self::assertSame('85070591730234615819726791673668173830', $large->expectedGrossNumerator);
        self::assertSame('9223372036854775807', $large->expectedGrossDenominator);
        self::assertSame('0', GrossDeliveryQuantity::zero()->expectedGrossNumerator);
        self::assertSame('1', GrossDeliveryQuantity::zero()->expectedGrossDenominator);
    }

    public function testRejectsInvalidProbabilityAndNegativeQuantity(): void
    {
        foreach ([[-1, 1], [2, 1], [0, 0], [0, -1]] as [$numerator, $denominator]) {
            try {
                new GrossProbability($numerator, $denominator);
                self::fail('Invalid probability accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        GrossDeliveryQuantity::fromProbability(-1, new GrossProbability(1, 2));
    }
}
