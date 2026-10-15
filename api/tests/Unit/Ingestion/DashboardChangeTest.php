<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Application\Dashboard\DashboardChange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Изменение к периоду сравнения — денежная арифметика (ADR-005):
 * целые числа, округление половины от нуля.
 */
final class DashboardChangeTest extends TestCase
{
    /**
     * @return iterable<string, array{?int, ?int, ?int}>
     */
    public static function relative(): iterable
    {
        yield 'рост' => [450_000, 400_000, 1_250];
        yield 'падение' => [300_000, 400_000, -2_500];
        yield 'без изменений' => [5, 5, 0];
        yield 'половина вверх' => [20_001, 20_000, 1];   // 0,5 б.п.
        yield 'половина вниз' => [19_999, 20_000, -1];   // −0,5 б.п.
        yield 'меньше половины' => [40_001, 40_000, 0];  // 0,25 б.п.
        yield 'с нуля' => [100, 0, null];
        yield 'нет текущего' => [null, 100, null];
        yield 'нет прошлого' => [100, null, null];
        // Нетто-расход ушёл в плюс сторно: знак изменения — от модуля базы.
        yield 'отрицательная база' => [100, -100, 20_000];
        yield 'крупные суммы' => [2_000_000_000_000, 1_000_000_000_000, 10_000];
    }

    #[DataProvider('relative')]
    public function testRelativeChangeInBasisPoints(?int $current, ?int $previous, ?int $expected): void
    {
        self::assertSame($expected, DashboardChange::relativeBps($current, $previous));
    }

    public function testPointsAreTheDifferenceOfPercentages(): void
    {
        // ДРР 12 % против 10 % — +2 п.п., не «+20 %».
        self::assertSame(200, DashboardChange::pointsBps(1_200, 1_000));
        self::assertSame(-150, DashboardChange::pointsBps(6_350, 6_500));
        self::assertSame(0, DashboardChange::pointsBps(0, 0));
        self::assertNull(DashboardChange::pointsBps(null, 1_000));
        self::assertNull(DashboardChange::pointsBps(1_000, null));
    }
}
