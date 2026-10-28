<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Domain\ValueObject\GrossProbability;
use App\Ingestion\Domain\ValueObject\OpenRetainedQuantities;
use App\Ingestion\Domain\ValueObject\ReturnProbability;
use Brick\Math\BigRational;
use PHPUnit\Framework\TestCase;

final class OpenRetainedQuantitiesTest extends TestCase
{
    public function testFutureReturnTailAppliesToExpectedGrossOfAlreadyPlacedUnits(): void
    {
        $quantities = OpenRetainedQuantities::fromProbabilities(100, new GrossProbability(1, 2), new ReturnProbability(1, 5));
        self::assertSame(100, $quantities->eligibleOpenQuantity);
        self::assertSame('50', $quantities->expectedGrossNumerator);
        self::assertSame('1', $quantities->expectedGrossDenominator);
        self::assertSame('10', $quantities->expectedOpenReturnsNumerator);
        self::assertSame('1', $quantities->expectedOpenReturnsDenominator);
        self::assertSame('40', $quantities->retainedOpenNumerator);
        self::assertSame('1', $quantities->retainedOpenDenominator);
    }

    public function testHorizonProbabilityZeroAndOneKeepTheirMeaning(): void
    {
        foreach ([[0, '0', '50'], [1, '50', '0']] as [$returned, $returns, $retained]) {
            $quantities = OpenRetainedQuantities::fromProbabilities(100, new GrossProbability(1, 2), new ReturnProbability($returned, 1));
            self::assertSame('50', $quantities->expectedGrossNumerator);
            self::assertSame($returns, $quantities->expectedOpenReturnsNumerator);
            self::assertSame($retained, $quantities->retainedOpenNumerator);
        }
    }

    public function testKnownZeroDoesNotInventMissingReturnProbability(): void
    {
        foreach ([OpenRetainedQuantities::zero(),
            OpenRetainedQuantities::fromProbabilities(0, new GrossProbability(1, 2), null),
            OpenRetainedQuantities::fromProbabilities(100, new GrossProbability(0, 100), null),
        ] as $quantities) {
            self::assertSame('0', $quantities->expectedGrossNumerator);
            self::assertSame('0', $quantities->expectedOpenReturnsNumerator);
            self::assertSame('0', $quantities->retainedOpenNumerator);
            self::assertSame('1', $quantities->expectedGrossDenominator);
            self::assertSame('1', $quantities->expectedOpenReturnsDenominator);
            self::assertSame('1', $quantities->retainedOpenDenominator);
        }
        self::assertSame(100, OpenRetainedQuantities::fromProbabilities(100, new GrossProbability(0, 100), null)->eligibleOpenQuantity);
    }

    public function testPositiveGrossCannotAssumeMissingReturnProbabilityIsZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OpenRetainedQuantities::fromProbabilities(100, new GrossProbability(1, 2), null);
    }

    public function testNegativeEligibleQuantityIsInvalidEvenWithGrossZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OpenRetainedQuantities::fromProbabilities(-1, new GrossProbability(0, 100), null);
    }

    public function testThirdsAndLargeProductsConserveExactGrossWithoutRounding(): void
    {
        foreach ([[1, new GrossProbability(1, 3), new ReturnProbability(1, 3), '1/3', '1/9', '2/9'],
            [\PHP_INT_MAX, new GrossProbability(\PHP_INT_MAX - 1, \PHP_INT_MAX), new ReturnProbability(1, 3), '9223372036854775806', '3074457345618258602', '6148914691236517204'],
            [\PHP_INT_MAX, new GrossProbability(1, 3), new ReturnProbability(1, 3), '9223372036854775807/3', '9223372036854775807/9', '18446744073709551614/9'],
        ] as [$eligible, $gross, $returns, $expectedGross, $expectedReturns, $expectedRetained]) {
            $quantities = OpenRetainedQuantities::fromProbabilities($eligible, $gross, $returns);
            $actualGross = BigRational::ofFraction($quantities->expectedGrossNumerator, $quantities->expectedGrossDenominator);
            $actualReturns = BigRational::ofFraction($quantities->expectedOpenReturnsNumerator, $quantities->expectedOpenReturnsDenominator);
            $actualRetained = BigRational::ofFraction($quantities->retainedOpenNumerator, $quantities->retainedOpenDenominator);
            self::assertTrue($actualGross->isEqualTo($expectedGross));
            self::assertTrue($actualReturns->isEqualTo($expectedReturns));
            self::assertTrue($actualRetained->isEqualTo($expectedRetained));
            self::assertTrue($actualRetained->plus($actualReturns)->isEqualTo($actualGross));
            self::assertFalse($actualRetained->isNegative());
            self::assertFalse($actualGross->isGreaterThan($eligible));
        }
    }
}
