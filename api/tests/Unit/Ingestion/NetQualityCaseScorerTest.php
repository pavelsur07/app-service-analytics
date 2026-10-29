<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Application\NetForecast as Net;
use App\Ingestion\Domain\ValueObject\NetQualityExact;
use App\Tests\Support\Builder\NetQualityObservationBuilder;
use PHPUnit\Framework\TestCase;

final class NetQualityCaseScorerTest extends TestCase
{
    public function testMoneySumsExactComponentsBeforeRoundingAndNullCannotBecomePartialZero(): void
    {
        $part = ['rawQuantity' => 1, 'model' => ['numerator' => '1', 'denominator' => '2'], 'simple' => ['numerator' => '1', 'denominator' => '2'], 'modelReason' => null, 'simpleReason' => null, 'unitPriceMinor' => '-1', 'currency' => 'RUB'];
        $parts = [['sourceRowId' => 'a', ...$part], ['sourceRowId' => 'b', ...$part]];
        $observation = NetQualityObservationBuilder::anObservation()->with('components', ['a', 'b'])->with('payload', ['parts' => $parts])->build();
        $truth = new Net\NetQualityTruthOutcome('ready', NetQualityExact::fraction('2'), null, ['s:a' => 1, 's:b' => 1], []);
        $row = (new Net\NetQualityCaseScorer())->score($observation, $truth);
        self::assertSame(['numerator' => '-1', 'denominator' => '1'], $row['methodMoney']);
        $parts[1]['model'] = null;
        $parts[1]['modelReason'] = 'insufficient_sample';
        $null = NetQualityObservationBuilder::anObservation()->with('components', ['a', 'b'])->with('payload', ['parts' => $parts])->build();
        $row = (new Net\NetQualityCaseScorer())->score($null, $truth);
        self::assertNull($row['method']);
        self::assertNull($row['methodMoney']);
        self::assertSame(['numerator' => '1', 'denominator' => '1'], $row['simple']);
        self::assertSame(['insufficient_sample'], $row['methodReasons']);
    }

    public function testNativeMoneyOverflowMakesOnlyMoneyUnavailable(): void
    {
        $part = ['sourceRowId' => 'a', 'rawQuantity' => 2, 'model' => ['numerator' => '2', 'denominator' => '1'], 'simple' => ['numerator' => '2', 'denominator' => '1'], 'unitPriceMinor' => (string) \PHP_INT_MAX, 'currency' => 'RUB'];
        $observation = NetQualityObservationBuilder::anObservation()->with('components', ['a'])->with('payload', ['parts' => [$part]])->build();
        $row = (new Net\NetQualityCaseScorer())->score($observation, new Net\NetQualityTruthOutcome('ready', NetQualityExact::fraction('2'), null, ['s:a' => 2], []));
        self::assertSame('money_minor_overflow', $row['moneyReason']);
        self::assertNull($row['methodMoney']);
        self::assertNotNull($row['method']);
    }
}
