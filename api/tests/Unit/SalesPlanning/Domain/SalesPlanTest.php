<?php

declare(strict_types=1);

namespace App\Tests\Unit\SalesPlanning\Domain;

use App\SalesPlanning\Domain\SalesPlan;
use App\SalesPlanning\Domain\SalesPlanStatus;
use App\Tests\Support\Builder\SalesPlanBuilder;
use PHPUnit\Framework\TestCase;

final class SalesPlanTest extends TestCase
{
    public function testNewPlanIsFirstDraftOfItsOwnLineage(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()->build();

        self::assertSame(SalesPlanStatus::Draft, $plan->status());
        self::assertTrue($plan->lineageId()->equals($plan->id()));
        self::assertSame(1, $plan->versionNumber());
    }

    public function testPeriodIsInclusiveCalendarDays(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()
            ->withPeriod(new \DateTimeImmutable('2026-07-01 18:30:00'), new \DateTimeImmutable('2026-07-03 09:00:00'))
            ->build();

        self::assertSame(3, $plan->periodDays());
        self::assertSame(
            ['2026-07-01', '2026-07-02', '2026-07-03'],
            array_map(static fn (\DateTimeImmutable $day): string => $day->format('Y-m-d'), $plan->days()),
        );
        self::assertSame('00:00:00', $plan->dateFrom()->format('H:i:s'));
        self::assertTrue($plan->covers(new \DateTimeImmutable('2026-07-03 23:59:59')));
        self::assertFalse($plan->covers(new \DateTimeImmutable('2026-07-04')));
    }

    public function testDatesInDifferentTimeZonesAreTheSameCalendarDays(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()
            ->withPeriod(
                new \DateTimeImmutable('2026-07-01 00:00:00', new \DateTimeZone('UTC')),
                new \DateTimeImmutable('2026-07-31 00:00:00', new \DateTimeZone('Europe/Moscow')),
            )
            ->build();

        self::assertSame(31, $plan->periodDays());
        self::assertCount(31, $plan->days());
        self::assertSame('2026-07-31', $plan->days()[30]->format('Y-m-d'));
        self::assertTrue($plan->covers(new \DateTimeImmutable('2026-07-31 23:30:00', new \DateTimeZone('Asia/Vladivostok'))));
        self::assertFalse($plan->covers(new \DateTimeImmutable('2026-08-01 00:30:00', new \DateTimeZone('Europe/Moscow'))));
    }

    public function testOneDayPlanIsAllowed(): void
    {
        $day = new \DateTimeImmutable('2026-07-15');

        self::assertSame(1, SalesPlanBuilder::aSalesPlan()->withPeriod($day, $day)->build()->periodDays());
    }

    public function testAcceptsPeriodAtTheLimit(): void
    {
        $from = new \DateTimeImmutable('2026-07-01');
        $plan = SalesPlanBuilder::aSalesPlan()
            ->withPeriod($from, $from->modify('+'.(SalesPlan::MaxPeriodDays - 1).' days'))
            ->build();

        self::assertSame(SalesPlan::MaxPeriodDays, $plan->periodDays());
    }

    public function testRejectsPeriodOverTheLimit(): void
    {
        $from = new \DateTimeImmutable('2026-07-01');

        $this->expectException(\InvalidArgumentException::class);

        SalesPlanBuilder::aSalesPlan()
            ->withPeriod($from, $from->modify('+'.SalesPlan::MaxPeriodDays.' days'))
            ->build();
    }

    public function testRejectsReversedPeriod(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SalesPlanBuilder::aSalesPlan()
            ->withPeriod(new \DateTimeImmutable('2026-07-02'), new \DateTimeImmutable('2026-07-01'))
            ->build();
    }

    public function testRejectsBlankName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SalesPlanBuilder::aSalesPlan()->withName('   ')->build();
    }

    public function testRejectsTooLongName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SalesPlanBuilder::aSalesPlan()->withName(str_repeat('я', SalesPlan::MaxNameLength + 1))->build();
    }

    public function testRejectsUnknownCurrency(): void
    {
        $this->expectException(\Throwable::class);

        SalesPlanBuilder::aSalesPlan()->withCurrency('XXZ')->build();
    }

    public function testRecordingLineChangeMovesUpdatedAt(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()->withCreatedAt(new \DateTimeImmutable('2026-06-25 10:00:00'))->build();

        $plan->recordLinesChanged(new \DateTimeImmutable('2026-06-25 11:00:00'));

        self::assertSame('2026-06-25 11:00:00', $plan->updatedAt()->format('Y-m-d H:i:s'));
    }

    public function testEveryLineChangeChangesThePlanEvenAtTheSameMoment(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()->build();
        $at = new \DateTimeImmutable('2026-06-25 11:00:00');

        $plan->recordLinesChanged($at);
        $plan->recordLinesChanged($at);

        self::assertSame(2, $plan->lineEdits());
    }
}
