<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Domain\ValueObject\NetForecastQuantities;
use App\Ingestion\Domain\ValueObject\NetForecastRevenue;
use PHPUnit\Framework\TestCase;

final class NetForecastRevenueTest extends TestCase
{
    protected function setUp(): void
    {
        self::assertTrue(class_exists(NetForecastRevenue::class), 'Exact revenue value must exist.');
    }

    public function testRoundingHappensOnlyAfterExactSum(): void
    {
        $half = self::value('1', '1', '2');
        self::assertSame(1, $half->toMoney()?->minorAmount());
        self::assertSame(1, $half->plus($half)->toMoney()?->minorAmount());
        $third = self::value('1', '1', '3');
        self::assertSame(0, $third->toMoney()?->minorAmount());
        self::assertSame(1, $third->plus($third)->plus($third)->toMoney()?->minorAmount());
        self::assertSame(-1, self::value('-1', '1', '2')->toMoney()?->minorAmount());
        self::assertSame(0, self::value('0')->toMoney()?->minorAmount());
        self::assertSame(-7, self::value('-7')->toMoney()?->minorAmount());
    }

    public function testEmptyIdentityAndStickyInputFailures(): void
    {
        $empty = NetForecastRevenue::empty();
        self::assertNull($empty->toMoney());
        self::assertSame('money_currency_missing', $empty->unavailableReason());
        $valid = self::value('7');
        self::assertSame(7, $empty->plus($valid)->toMoney()?->minorAmount());
        self::assertSame(7, $valid->plus($empty)->toMoney()?->minorAmount());
        $failure = NetForecastRevenue::unavailable('source_failure');
        self::assertSame('source_failure', $failure->plus($valid)->unavailableReason());
        self::assertSame('source_failure', $valid->plus($failure)->unavailableReason());
        foreach ([NetForecastQuantities::zero(), self::quantity()] as $q) {
            foreach ([[null, 'RUB', 'money_price_missing'], ['1', null, 'money_currency_missing'], ['', 'RUB', 'money_input_invalid'], ['01', 'RUB', 'money_input_invalid'], ['-0', 'RUB', 'money_input_invalid'], ['+1', 'RUB', 'money_input_invalid'], ['1e2', 'RUB', 'money_input_invalid'], ['1.0', 'RUB', 'money_input_invalid'], [' 1', 'RUB', 'money_input_invalid'], ['1', 'xxx', 'money_input_invalid'], ['1', '', 'money_input_invalid'], ['9223372036854775808', 'RUB', 'money_overflow'], ['-9223372036854775809', 'RUB', 'money_overflow'], [str_repeat('9', 100000), 'RUB', 'money_overflow'], [str_repeat('0', 21), 'RUB', 'money_input_invalid'], [str_repeat('x', 21), 'RUB', 'money_input_invalid']] as [$price, $currency, $reason]) {
                $result = NetForecastRevenue::fromSource($price, $currency, $q);
                self::assertNull($result->toMoney());
                self::assertSame($reason, $result->unavailableReason());
                self::assertSame($reason, $result->plus($valid)->unavailableReason());
            }
        }
    }

    public function testCurrencyMismatchIncludesZeroPrices(): void
    {
        foreach (['0', '1'] as $price) {
            try {
                self::value($price)->plus(self::value($price, currency: 'USD'));
                self::fail('Different currencies were added.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testNativeBoundsAndExactOverflowCancellation(): void
    {
        self::assertSame(\PHP_INT_MIN, self::value((string) \PHP_INT_MIN)->toMoney()?->minorAmount());
        self::assertSame(\PHP_INT_MAX, self::value((string) \PHP_INT_MAX)->toMoney()?->minorAmount());
        $large = self::value((string) \PHP_INT_MAX)->plus(self::value('1'));
        try {
            $large->toMoney();
            self::fail('Native output overflow must throw.');
        } catch (\OverflowException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(\PHP_INT_MAX, $large->plus(self::value('-1'))->toMoney()?->minorAmount());
        self::assertSame(0, self::value((string) \PHP_INT_MAX, '2')->plus(self::value((string) -\PHP_INT_MAX, '2'))->toMoney()?->minorAmount());
    }

    public function testPrecisionAndPrivateStorageAreBounded(): void
    {
        $huge = NetForecastQuantities::fromComponents(str_repeat('9', 16384), '0', '0', '1', '0', '1');
        self::assertSame('money_precision_exceeded', NetForecastRevenue::fromSource('99', 'RUB', $huge)->unavailableReason());
        $a = self::value('1', '1', str_repeat('9', 8192));
        $b = self::value('1', '1', '1'.str_repeat('0', 8193));
        self::assertSame('money_precision_exceeded', $a->plus($b)->unavailableReason());
        self::assertGreaterThan(16384, $a->storageBytes());
        self::assertSame('{}', json_encode($a, \JSON_THROW_ON_ERROR));
        self::assertGreaterThan(0, NetForecastRevenue::empty()->storageBytes());
    }

    public function testSharedDenominatorDoesNotExhaustPrecisionForSmallExactResult(): void
    {
        $fraction = self::value('1', '1', str_repeat('9', 9000));
        $sum = $fraction->plus($fraction);
        self::assertNull($sum->unavailableReason());
        self::assertSame(0, $sum->toMoney()?->minorAmount());
        $large = NetForecastQuantities::fromComponents(str_repeat('9', 16384), '0', '0', '1', '0', '1');
        $revenue = NetForecastRevenue::fromSource('1', 'RUB', $large);
        self::assertNull($revenue->unavailableReason());
        self::assertSame(0, $revenue->plus(NetForecastRevenue::fromSource('-1', 'RUB', $large))->toMoney()?->minorAmount());
        $a = self::value('1', '1', '6');
        $b = self::value('1', '1', '10');
        self::assertSame(4, $a->plus($b)->plus(self::value('1', '11', '15'))->plus(self::value('3'))->toMoney()?->minorAmount());
    }

    public function testCommonDenominatorReductionAndSignedCancellationStayExact(): void
    {
        $sixth = self::value('1', '1', '6');
        $fifteenth = self::value('1', '1', '15');
        $sum = NetForecastRevenue::empty();
        for ($i = 0; $i < 30; ++$i) {
            $sum = $sum->plus($sixth)->plus($fifteenth);
        }
        self::assertSame(7, $sum->toMoney()?->minorAmount());
        self::assertSame(1, $sixth->plus(self::value('1', '1', '3'))->toMoney()?->minorAmount());
        self::assertSame(0, $sixth->plus(self::value('-1', '1', '6'))->toMoney()?->minorAmount());
        self::assertSame(1, $sixth->plus(self::value('-1', '1', '6'))->plus(self::value('1', '1', '2'))->toMoney()?->minorAmount());
    }

    private static function value(string $price, string $numerator = '1', string $denominator = '1', string $currency = 'RUB'): NetForecastRevenue
    {
        return NetForecastRevenue::fromSource($price, $currency, self::quantity($numerator, $denominator));
    }

    private static function quantity(string $numerator = '1', string $denominator = '1'): NetForecastQuantities
    {
        return NetForecastQuantities::fromComponents('0', $numerator, '0', '1', $numerator, $denominator);
    }
}
