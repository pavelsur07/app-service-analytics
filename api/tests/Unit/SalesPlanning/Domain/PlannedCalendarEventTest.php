<?php

declare(strict_types=1);

namespace App\Tests\Unit\SalesPlanning\Domain;

use App\SalesPlanning\Domain\PlannedCalendarEvent;
use App\SalesPlanning\Domain\SalesPlanCalendarKind;
use App\Tests\Support\Builder\SalesPlanBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlannedCalendarEventTest extends TestCase
{
    public function testSalesAndReplenishmentMayShareOneCalendarDayWithoutTimeZoneShifts(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()->withPeriod(new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-07-31'))->build();
        $sales = new PlannedCalendarEvent(SalesPlanCalendarKind::Sales, null,
            new \DateTimeImmutable('2026-07-01 23:59:00 Pacific/Auckland'), new \DateTimeImmutable('2026-07-31 00:01:00 America/New_York'));
        $replenishment = new PlannedCalendarEvent(SalesPlanCalendarKind::Replenishment, 'SKU',
            new \DateTimeImmutable('2026-07-01 23:59:00 Pacific/Auckland'), new \DateTimeImmutable('2026-07-01 00:01:00 America/New_York'));
        $sales->assertWithin($plan);
        $replenishment->assertWithin($plan);
        self::assertSame('2026-07-01', $sales->dateFrom->format('Y-m-d'));
        self::assertSame('2026-07-31', $sales->dateTo->format('Y-m-d'));
        self::assertEquals($replenishment->dateFrom, $replenishment->dateTo);
        self::assertSame('SKU', $replenishment->marketplaceSku);
    }

    #[DataProvider('invalidEvents')]
    public function testRejectsInvalidEvent(string $kind, ?string $sku, string $from, string $to): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PlannedCalendarEvent(SalesPlanCalendarKind::from($kind), $sku, new \DateTimeImmutable($from), new \DateTimeImmutable($to));
    }

    /** @return iterable<string, array{string, ?string, string, string}> */
    public static function invalidEvents(): iterable
    {
        yield 'computed start cannot be stored' => ['season_start', null, '2026-07-01', '2026-07-01'];
        yield 'computed end cannot be stored' => ['season_end', null, '2026-07-31', '2026-07-31'];
        yield 'replenishment is a point' => ['replenishment', null, '2026-07-01', '2026-07-02'];
        yield 'stop is a point' => ['replenishment_stop', null, '2026-07-01', '2026-07-02'];
        yield 'reversed interval' => ['sales', null, '2026-07-02', '2026-07-01'];
        yield 'empty sku' => ['sales', '  ', '2026-07-01', '2026-07-01'];
        yield 'NUL sku' => ['sales', "bad\0", '2026-07-01', '2026-07-01'];
        yield 'oversized sku' => ['sales', str_repeat('x', 65), '2026-07-01', '2026-07-01'];
    }

    public function testEventCannotExtendOutsideSeason(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()->withPeriod(new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-07-31'))->build();
        $event = new PlannedCalendarEvent(SalesPlanCalendarKind::Sales, null, new \DateTimeImmutable('2026-06-30'), new \DateTimeImmutable('2026-07-02'));
        $this->expectException(\InvalidArgumentException::class);
        $event->assertWithin($plan);
    }

    public function testSingleDaySalesAndLastDayStopAreAllowed(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()->withPeriod(new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-07-31'))->build();
        $date = new \DateTimeImmutable('2026-07-31');
        $sales = new PlannedCalendarEvent(SalesPlanCalendarKind::Sales, null, $date, $date);
        $stop = new PlannedCalendarEvent(SalesPlanCalendarKind::ReplenishmentStop, null, $date, $date);
        $sales->assertWithin($plan);
        $stop->assertWithin($plan);
        self::assertSame($sales->dateFrom->format('Y-m-d'), $stop->dateFrom->format('Y-m-d'));
    }
}
