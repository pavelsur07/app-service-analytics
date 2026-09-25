<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application;

use App\Ingestion\Application\Buyout\BuyoutBacktest;
use App\Ingestion\Application\Buyout\BuyoutBacktestPair;
use App\Ingestion\Infrastructure\Query\Buyout\BuyoutDailyRow;
use PHPUnit\Framework\TestCase;

final class BuyoutBacktestTest extends TestCase
{
    public function testPairsOnlyDaysPreliminaryOnAsOfAndMatureNowWithinHorizon(): void
    {
        $asOf = new \DateTimeImmutable('2026-09-20');
        $final = [
            '2026-09-19' => self::row('2026-09-19', 'mature', actual: 4000),
            '2026-09-18' => self::row('2026-09-18', 'mature', actual: 5000),
            '2026-09-17' => self::row('2026-09-17', 'preliminary', actual: null),
            '2026-09-04' => self::row('2026-09-04', 'mature', actual: 4500),
            '2026-09-05' => self::row('2026-09-05', 'mature', actual: 4200),
        ];

        $pairs = BuyoutBacktest::pairs($asOf, [
            self::row('2026-09-19', 'preliminary', projected: 4500, known: 6000),
            // Уже зрелый на дату прогноза — прогноз там равен факту, не проверка.
            self::row('2026-09-18', 'mature', projected: 5000, known: 5000),
            // Сегодня ещё не созрел — итога нет.
            self::row('2026-09-17', 'preliminary', projected: 4000),
            // 16 дней — за пределами горизонта.
            self::row('2026-09-04', 'preliminary', projected: 4000),
            self::row('2026-09-05', 'preliminary', projected: null, known: null),
        ], $final);

        self::assertEquals([
            new BuyoutBacktestPair('2026-09-20', '2026-09-19', 1, 4500, 6000, 4000),
            new BuyoutBacktestPair('2026-09-20', '2026-09-05', 15, null, null, 4200),
        ], $pairs);
    }

    public function testSummarizesAbsoluteErrorBiasAndMissingForecastsPerHorizonBucket(): void
    {
        $buckets = BuyoutBacktest::summarize([
            new BuyoutBacktestPair('2026-09-20', '2026-09-19', 1, 4500, 6000, 4000),
            new BuyoutBacktestPair('2026-09-20', '2026-09-18', 2, 3700, 5000, 4000),
            new BuyoutBacktestPair('2026-09-20', '2026-09-15', 5, null, 4400, 4200),
            new BuyoutBacktestPair('2026-09-20', '2026-09-08', 12, 4101, null, 4100),
        ]);

        self::assertSame(['1–2', '3–5', '6–9', '10–15', 'все'], array_column($buckets, 'label'));

        [$short, $middle, $empty, $long, $all] = $buckets;
        self::assertSame(2, $short->cohorts);
        self::assertSame(400, $short->forecastMaeBps);
        self::assertSame(100, $short->forecastBiasBps);
        self::assertSame(1500, $short->naiveMaeBps);
        self::assertSame(1500, $short->naiveBiasBps);

        self::assertSame(1, $middle->cohorts);
        self::assertSame(0, $middle->forecastCount);
        self::assertNull($middle->forecastMaeBps);
        self::assertSame(200, $middle->naiveMaeBps);

        self::assertSame(0, $empty->cohorts);
        self::assertNull($empty->forecastMaeBps);
        self::assertNull($empty->naiveMaeBps);

        self::assertSame(1, $long->forecastMaeBps);
        self::assertSame(0, $long->naiveCount);

        self::assertSame(4, $all->cohorts);
        self::assertSame(3, $all->forecastCount);
        self::assertSame(267, $all->forecastMaeBps);
        self::assertSame(67, $all->forecastBiasBps);
        self::assertSame(3, $all->naiveCount);
        self::assertSame(1067, $all->naiveMaeBps);
    }

    private static function row(
        string $date,
        string $maturity,
        ?int $actual = null,
        ?int $projected = null,
        ?int $known = null,
    ): BuyoutDailyRow {
        return new BuyoutDailyRow(
            date: $date,
            actualBuyoutRateBps: $actual,
            projectedBuyoutRateBps: $projected,
            resolutionRateBps: null,
            orderedQuantity: 1,
            resolvedQuantity: 0,
            projectedBuyoutQuantity: null,
            maturityStatus: $maturity,
            inFlightRateBps: null,
            knownBuyoutRateBps: $known,
        );
    }
}
