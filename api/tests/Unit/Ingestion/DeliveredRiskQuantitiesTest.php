<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Domain\ValueObject\DeliveredRiskQuantities;
use App\Ingestion\Domain\ValueObject\ReturnProbability;
use Brick\Math\BigRational;
use PHPUnit\Framework\TestCase;

final class DeliveredRiskQuantitiesTest extends TestCase
{
    public function testExactGoldensPreserveRemainingUnitsAndFractionalQuantities(): void
    {
        foreach ([
            [80, 10, 80, '10', '1', '70', '1'],
            [70, 5, 70, '5', '1', '65', '1'],
            [1, 1, 3, '1', '3', '2', '3'],
            [70, 0, 100, '0', '1', '70', '1'],
            [70, 100, 100, '70', '1', '0', '1'],
        ] as [$quantity, $numerator, $denominator, $futureN, $futureD, $retainedN, $retainedD]) {
            $result = DeliveredRiskQuantities::fromProbability($quantity, new ReturnProbability($numerator, $denominator));
            self::assertSame([$futureN, $futureD, $retainedN, $retainedD], [$result->futureReturnsNumerator, $result->futureReturnsDenominator, $result->retainedDeliveredNumerator, $result->retainedDeliveredDenominator]);
            self::assertSame($quantity, $result->currentD);
        }
    }

    public function testLargeProductsStayExactAndConserveQuantity(): void
    {
        $whole = DeliveredRiskQuantities::fromProbability(\PHP_INT_MAX, new ReturnProbability(\PHP_INT_MAX - 1, \PHP_INT_MAX));
        self::assertSame((string) (\PHP_INT_MAX - 1), $whole->futureReturnsNumerator);
        self::assertSame('1', $whole->futureReturnsDenominator);
        self::assertSame('1', $whole->retainedDeliveredNumerator);
        self::assertSame('1', $whole->retainedDeliveredDenominator);

        $fractional = DeliveredRiskQuantities::fromProbability(\PHP_INT_MAX, new ReturnProbability(\PHP_INT_MAX - 2, \PHP_INT_MAX - 1));
        self::assertSame('85070591730234615828950163710522949635', $fractional->futureReturnsNumerator);
        self::assertSame('9223372036854775806', $fractional->futureReturnsDenominator);
        self::assertSame('9223372036854775807', $fractional->retainedDeliveredNumerator);
        self::assertSame('9223372036854775806', $fractional->retainedDeliveredDenominator);
        $future = BigRational::ofFraction($fractional->futureReturnsNumerator, $fractional->futureReturnsDenominator);
        $retained = BigRational::ofFraction($fractional->retainedDeliveredNumerator, $fractional->retainedDeliveredDenominator);
        self::assertTrue($future->plus($retained)->isEqualTo(\PHP_INT_MAX));
        self::assertTrue($future->isGreaterThanOrEqualTo(0));
        self::assertTrue($future->isLessThanOrEqualTo(\PHP_INT_MAX));
    }

    public function testKnownZeroAndClosedHorizonKeepCanonicalFractions(): void
    {
        foreach ([0, 70] as $quantity) {
            $result = DeliveredRiskQuantities::withoutFutureReturns($quantity);
            self::assertSame('0', $result->futureReturnsNumerator);
            self::assertSame('1', $result->futureReturnsDenominator);
            self::assertSame((string) $quantity, $result->retainedDeliveredNumerator);
            self::assertSame('1', $result->retainedDeliveredDenominator);
        }
    }

    public function testNegativeExposureIsRejectedWithoutClipping(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DeliveredRiskQuantities::fromProbability(-1, new ReturnProbability(1, 2));
    }
}
