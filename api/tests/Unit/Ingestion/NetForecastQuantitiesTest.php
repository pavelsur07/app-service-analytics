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

    public function testZeroAdditionPreservesLargeBasesAndZeroNetIsNotAnIdentity(): void
    {
        $large = NetForecastQuantities::fromComponents('9223372036854775808', '9223372036854775809', '1', '3', '1', '7');
        self::assertEquals($large, $large->plus(NetForecastQuantities::zero()));
        self::assertEquals($large, NetForecastQuantities::zero()->plus($large));
        $netZero = NetForecastQuantities::fromComponents('5', '9', '5', '1', '0', '1');
        $sum = $netZero->plus($large);
        self::assertSame('9223372036854775813', $sum->currentD);
        self::assertSame('9223372036854775818', $sum->eligibleOpenQuantity);
        self::assertSame('16', $sum->futureReturnsNumerator);
        self::assertSame('3', $sum->futureReturnsDenominator);
        self::assertSame($large->expectedNetNumerator, $sum->expectedNetNumerator);
        self::assertSame($large->expectedNetDenominator, $sum->expectedNetDenominator);
    }

    public function testPublicJsonKeepsOnlyCanonicalStrings(): void
    {
        $q = NetForecastQuantities::fromComponents('70', '50', '10', '2', '75', '3');
        self::assertEquals([
            'currentD' => '70', 'eligibleOpenQuantity' => '50',
            'futureReturnsNumerator' => '5', 'futureReturnsDenominator' => '1',
            'retainedOpenNumerator' => '25', 'retainedOpenDenominator' => '1',
            'expectedNetNumerator' => '90', 'expectedNetDenominator' => '1',
        ], json_decode(json_encode($q, \JSON_THROW_ON_ERROR), true, 4, \JSON_THROW_ON_ERROR));
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
