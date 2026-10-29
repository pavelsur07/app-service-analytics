<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Application\NetForecast as Net;
use App\Ingestion\Domain\ValueObject\NetQualityExact;
use App\Tests\Support\Builder\NetQualityPairedObservationBuilder as Pair;
use PHPUnit\Framework\TestCase;

final class NetQualityMetricsTest extends TestCase
{
    public function testExactQuantityErrorsShareOnePairedPopulation(): void
    {
        $a = Pair::aPair()->withQuantities('1', '3', '1', '2', '0', '1')->build();
        $b = Pair::aPair()->withQuantities('2', '3', '1', '2', '1', '1')->build();
        $result = (new Net\NetQualityMetrics())->calculate([$a, $b]);
        self::assertSame(2, $result->count);
        self::assertSame(['numerator' => '1', 'denominator' => '3'], $result->method['mae']);
        self::assertSame(['numerator' => '0', 'denominator' => '1'], $result->method['signedBias']);
        self::assertSame(['numerator' => '2', 'denominator' => '3'], $result->method['wape']);
        self::assertSame(['numerator' => '1', 'denominator' => '2'], $result->simple['mae']);
    }

    public function testMoneyPreservesSignsAndRoundsOnlyAfterSummingEachScope(): void
    {
        $a = Pair::aPair()->withMoney('RUB', ['1', '2'], ['1', '1'], ['1', '1'])->build();
        $b = Pair::aPair()->withMoney('RUB', ['-1', '2'], ['-1', '1'], ['-1', '1'])->build();
        $result = (new Net\NetQualityMetrics())->calculate([$a, $b]);
        self::assertIsArray($result->money['RUB']['methodExact']);
        self::assertIsArray($result->money['RUB']['methodRounded']);
        self::assertSame(['numerator' => '1', 'denominator' => '2'], $result->money['RUB']['methodExact']['mae']);
        self::assertSame(['numerator' => '0', 'denominator' => '1'], $result->money['RUB']['methodRounded']['mae']);
        self::assertSame(['numerator' => '2', 'denominator' => '1'], $result->money['RUB']['methodExact']['truthDenominator']);
    }

    public function testAllZeroTruthKeepsMaeButHasNoWape(): void
    {
        $row = Pair::aPair()->withQuantities('1', '1', '1', '1', '0', '1')->build();
        $result = (new Net\NetQualityMetrics())->calculate([$row]);
        self::assertNull($result->method['wape']);
        self::assertSame('zero_truth_denominator', $result->method['wapeReason']);
        self::assertSame(['numerator' => '1', 'denominator' => '1'], $result->method['mae']);
    }

    public function testRepeatingTheSameCaseCannotInflateMetricsN(): void
    {
        $row = Pair::aPair()->build();
        $this->expectException(\DomainException::class);
        (new Net\NetQualityMetrics())->calculate([$row, $row]);
    }

    public function testNegativeQuantityIsInvalidEvenThoughSignedMoneyIsValid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Pair::aPair()->withQuantities('-1', '1', '1', '1', '1', '1')->build();
    }

    public function testFractionsRejectNonCanonicalSignsAndPrecisionOverflow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NetQualityExact::fraction('-0', '1');
    }
}
