<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Application\InitialBackfillWindow;
use PHPUnit\Framework\TestCase;

/**
 * Ступень 1 первичной загрузки (ADR-021): текущий месяц, сразу, вперёд
 * остальных. Не «последние 30 дней» — продукт контролирует план-факт,
 * а план-факт живёт календарным месяцем.
 */
final class InitialBackfillWindowTest extends TestCase
{
    public function testFirstDayOfMonthGivesExactlyThatDay(): void
    {
        $dates = InitialBackfillWindow::businessDates(new \DateTimeImmutable('2026-09-01 10:00:00', new \DateTimeZone('Europe/Moscow')));

        self::assertSame(['2026-09-01'], $dates);
    }

    public function testMidMonthGivesEveryDayFromTheFirst(): void
    {
        $dates = InitialBackfillWindow::businessDates(new \DateTimeImmutable('2026-09-05 10:00:00', new \DateTimeZone('Europe/Moscow')));

        self::assertSame(
            ['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-09-05'],
            $dates,
        );
    }

    public function testLastDayOfLongMonthIsIncluded(): void
    {
        $dates = InitialBackfillWindow::businessDates(new \DateTimeImmutable('2026-01-31 23:00:00', new \DateTimeZone('Europe/Moscow')));

        self::assertCount(31, $dates);
        self::assertSame('2026-01-01', $dates[0]);
        self::assertSame('2026-01-31', $dates[30]);
    }

    public function testBusinessDateFollowsMarketplaceTimezoneNotUtc(): void
    {
        // 31 августа 22:00 UTC — это уже 1 сентября в Москве (ADR-009:
        // бизнес-дата в часовом поясе площадки). Считать по UTC значило
        // бы грузить весь август вместо одного дня сентября.
        $dates = InitialBackfillWindow::businessDates(new \DateTimeImmutable('2026-08-31 22:00:00', new \DateTimeZone('UTC')));

        self::assertSame(['2026-09-01'], $dates);
    }
}
