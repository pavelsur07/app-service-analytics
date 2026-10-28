<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Domain\ValueObject\NetForecastQuantities;
use PHPUnit\Framework\TestCase;

final class NetForecastQuantitiesTest extends TestCase
{
    protected function setUp(): void
    {
        self::assertTrue(class_exists(NetForecastQuantities::class), 'Exact net quantities value must exist.');
    }

    public function testSeventyMinusFivePlusTwentyFiveIsNinety(): void
    {
        $q = NetForecastQuantities::fromComponents('70', '50', '10', '2', '75', '3');
        self::assertSame('5', $q->futureReturnsNumerator);
        self::assertSame('1', $q->futureReturnsDenominator);
        self::assertSame('25', $q->retainedOpenNumerator);
        self::assertSame('90', $q->expectedNetNumerator);
        self::assertSame('1', $q->expectedNetDenominator);
    }

    public function testExactAdditionExceedsNativeIntegerWithoutRounding(): void
    {
        $one = NetForecastQuantities::fromComponents('9223372036854775807', '9223372036854775807', '1', '3', '1', '7');
        $two = NetForecastQuantities::fromComponents('9223372036854775807', '9223372036854775807', '1', '7', '1', '3');
        $sum = $one->plus($two);
        self::assertSame('18446744073709551614', $sum->currentD);
        self::assertSame('18446744073709551614', $sum->eligibleOpenQuantity);
        self::assertSame('10', $sum->futureReturnsNumerator);
        self::assertSame('21', $sum->futureReturnsDenominator);
        self::assertSame('10', $sum->retainedOpenNumerator);
        self::assertSame('21', $sum->retainedOpenDenominator);
        self::assertSame('18446744073709551614', $sum->expectedNetNumerator);
        self::assertSame('1', $sum->expectedNetDenominator);
        self::assertEquals($sum, $sum->plus(NetForecastQuantities::zero()));
    }

    public function testZeroIsCanonicalAndUnreducedInputsAreReduced(): void
    {
        $zero = NetForecastQuantities::fromComponents('0', '0', '0', '999', '0', '7');
        self::assertEquals(NetForecastQuantities::zero(), $zero);
        foreach (['futureReturns', 'retainedOpen', 'expectedNet'] as $name) {
            self::assertSame('0', $zero->{$name.'Numerator'});
            self::assertSame('1', $zero->{$name.'Denominator'});
        }
    }

    public function testInvalidAndOverboundComponentsAreRejectedBeforeMathParsing(): void
    {
        foreach ([
            ['-1', '0', '0', '1', '0', '1'], ['01', '0', '0', '1', '0', '1'],
            ['1', '0', '2', '1', '0', '1'], ['0', '1', '0', '1', '2', '1'],
            ['1', '1', '0', '0', '0', '1'], ['1', '1', '0', '-1', '0', '1'],
            ['+1', '1', '0', '1', '0', '1'], ['1e3', '1', '0', '1', '0', '1'],
            ['', '1', '0', '1', '0', '1'], ['1', '1', '0', '01', '0', '1'],
            [str_repeat('9', 16385), '1', '0', '1', '0', '1'],
            ['1', '1', '0', str_repeat('9', 16385), '0', '1'],
        ] as $input) {
            try {
                NetForecastQuantities::fromComponents(...$input);
                self::fail('Invalid quantities accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testPrecisionOverflowOfExactSumIsRefused(): void
    {
        $max = str_repeat('9', 16384);
        $quantity = NetForecastQuantities::fromComponents($max, '0', '0', '1', '0', '1');
        $this->expectException(\OverflowException::class);
        $quantity->plus(NetForecastQuantities::fromComponents('1', '0', '0', '1', '0', '1'));
    }
}
