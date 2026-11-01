<?php

declare(strict_types=1);

namespace App\Tests\Unit\ProductResearchOzon;

use App\ProductResearchOzon\Domain\PeriodRange;
use App\ProductResearchOzon\Domain\ResearchCalendar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResearchCalendarTest extends TestCase
{
    public function testSeasonsDeduplicateMonthsAndKeepNames(): void
    {
        $preview = (new ResearchCalendar())->preview([
            new PeriodRange('2025-01', '2025-03', 'Прошлый сезон'),
            new PeriodRange('2026-01', '2026-03', 'Этот сезон'),
            new PeriodRange('2026-01', '2026-03', 'Этот сезон'),
        ], false, new \DateTimeImmutable('2026-04-15T12:00:00Z'));
        self::assertCount(6, $preview->months);
        self::assertCount(2, $preview->periods);
        self::assertSame('янв–мар 2025, янв–мар 2026', $preview->summary);
        self::assertSame('Этот сезон', $preview->periods[1]->name);
        self::assertSame('Хиджабы · янв–мар 2025, янв–мар 2026', $preview->suggestedName('Одежда/Хиджабы'));
    }

    public function testOverlapsYearBoundaryAndLeapDays(): void
    {
        $calendar = new ResearchCalendar();
        $now = new \DateTimeImmutable('2026-11-01Z');
        $preview = $calendar->preview([new PeriodRange('2025-03', '2025-05'), new PeriodRange('2025-05', '2025-07')], false, $now);
        self::assertCount(5, $preview->months);
        $preview = $calendar->preview([new PeriodRange('2024-02', '2024-02'), new PeriodRange('2025-02', '2025-02')], false, $now);
        self::assertSame([29, 28], array_map(static fn (\App\ProductResearchOzon\Domain\MonthSlice $month): int => $month->days, $preview->months));
        self::assertCount(3, $calendar->preview([new PeriodRange('2025-12', '2026-02')], false, $now)->months);
    }

    public function testCurrentMonthAndMoscowBoundary(): void
    {
        $calendar = new ResearchCalendar();
        $preview = $calendar->preview([new PeriodRange('2026-03', '2026-03')], true, new \DateTimeImmutable('2026-03-15T09:00:00Z'));
        self::assertSame('2026-03-14', $preview->months[0]->dateTo);
        self::assertSame(14, $preview->months[0]->days);
        self::assertTrue($preview->months[0]->partial);
        $preview = $calendar->preview([new PeriodRange('2026-03', '2026-03')], true, new \DateTimeImmutable('2026-03-31T21:30:00Z'));
        self::assertSame('2026-04-01', $preview->calendarDate);
        self::assertFalse($preview->months[0]->partial);
        self::assertSame(31, $preview->months[0]->days);
        $preview = $calendar->preview([new PeriodRange('2026-03', '2026-03')], true, new \DateTimeImmutable('2026-03-02T09:00:00Z'));
        self::assertSame(1, $preview->months[0]->days);
    }

    public function testShortcutsAndLongSuggestedName(): void
    {
        $calendar = new ResearchCalendar();
        $now = new \DateTimeImmutable('2026-04-15Z');
        foreach ([3 => '2026-01', 6 => '2025-10', 12 => '2025-04'] as $count => $first) {
            $range = $calendar->lastClosedMonths($count, $now);
            self::assertSame($first, $range->fromMonth);
            self::assertSame('2026-03', $range->toMonth);
        }
        $ranges = $calendar->withPreviousYear(new PeriodRange('2026-01', '2026-03'));
        self::assertSame('2025-01', $ranges[1]->fromMonth);
        $preview = $calendar->preview($ranges, false, $now);
        self::assertLessThanOrEqual(200, mb_strlen($preview->suggestedName(str_repeat('я', 2048))));
    }

    /** @return iterable<string, array{list<PeriodRange>, bool, string}> */
    public static function invalidPeriods(): iterable
    {
        yield 'empty' => [[], false, '2026-04-15Z'];
        yield 'future' => [[new PeriodRange('2026-05', '2026-05')], true, '2026-04-15Z'];
        yield 'current without flag' => [[new PeriodRange('2026-04', '2026-04')], false, '2026-04-15Z'];
        yield 'first Moscow day' => [[new PeriodRange('2026-04', '2026-04')], true, '2026-03-31T21:30:00Z'];
        yield '25 months' => [[new PeriodRange('2024-01', '2026-01')], false, '2026-04-15Z'];
        yield '25 ranges' => [array_fill(0, 25, new PeriodRange('2026-01', '2026-01')), false, '2026-04-15Z'];
    }

    /** @param list<PeriodRange> $ranges */
    #[DataProvider('invalidPeriods')]
    public function testInvalidSelectionsAreRejected(array $ranges, bool $flag, string $now): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ResearchCalendar())->preview($ranges, $flag, new \DateTimeImmutable($now));
    }

    #[DataProvider('invalidMonthFormats')]
    public function testStrictRangeInput(string $from, string $to): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PeriodRange($from, $to);
    }

    /** @return iterable<array{string,string}> */
    public static function invalidMonthFormats(): iterable
    {
        yield ['2026-02', '2026-01'];
        yield ['2026-1', '2026-02'];
        yield ['2026-01-01', '2026-02'];
        yield ["2026-01\n", '2026-02'];
        yield ['2026-13', '2026-13'];
    }
}
