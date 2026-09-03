<?php

declare(strict_types=1);

namespace App\Tests\Unit\Links\Application;

use App\Links\Application\MonthPeriod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MonthPeriodTest extends TestCase
{
    public function testCurrentMonthEndsAfterToday(): void
    {
        $period = MonthPeriod::fromString(
            '2026-09',
            new \DateTimeImmutable('2026-09-03 12:00:00 UTC'),
        );

        self::assertSame('2026-09', $period->value);
        self::assertSame('2026-09-01', $period->start->format('Y-m-d'));
        self::assertSame('2026-09-04', $period->endExclusive->format('Y-m-d'));
        self::assertSame('2026-09-03', $period->lastIncludedDay->format('Y-m-d'));
    }

    public function testPastMonthIncludesItsWholeCalendarRange(): void
    {
        $period = MonthPeriod::fromString(
            '2026-02',
            new \DateTimeImmutable('2026-09-03 12:00:00 UTC'),
        );

        self::assertSame('2026-02-01', $period->start->format('Y-m-d'));
        self::assertSame('2026-03-01', $period->endExclusive->format('Y-m-d'));
        self::assertSame('2026-02-28', $period->lastIncludedDay->format('Y-m-d'));
    }

    #[DataProvider('invalidMonths')]
    public function testRejectsMalformedMonth(string $month): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('month_invalid');

        MonthPeriod::fromString($month, new \DateTimeImmutable('2026-09-03 12:00:00 UTC'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidMonths(): iterable
    {
        yield 'missing zero' => ['2026-9'];
        yield 'month zero' => ['2026-00'];
        yield 'month thirteen' => ['2026-13'];
        yield 'day included' => ['2026-09-01'];
    }

    public function testRejectsFutureMonth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('month_in_future');

        MonthPeriod::fromString('2026-10', new \DateTimeImmutable('2026-09-03 12:00:00 UTC'));
    }
}
