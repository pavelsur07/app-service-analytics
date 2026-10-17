<?php

declare(strict_types=1);

namespace App\Tests\Unit\SalesPlanning\Domain;

use App\SalesPlanning\Domain\EvenDayDistribution;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvenDayDistributionTest extends TestCase
{
    /**
     * @param list<int> $expected
     */
    #[DataProvider('splits')]
    public function testSplitsTotalOverDays(int $total, int $days, array $expected): void
    {
        self::assertSame($expected, (new EvenDayDistribution())->split($total, $days));
    }

    /**
     * @return iterable<string, array{int, int, list<int>}>
     */
    public static function splits(): iterable
    {
        yield 'делится ровно' => [9, 3, [3, 3, 3]];
        yield 'остаток первым дням' => [11, 4, [3, 3, 3, 2]];
        yield 'штук меньше, чем дней' => [2, 5, [1, 1, 0, 0, 0]];
        yield 'ноль' => [0, 3, [0, 0, 0]];
        yield 'один день' => [7, 1, [7]];
    }

    public function testSumAlwaysEqualsTotalAndDaysDifferByAtMostOne(): void
    {
        $distribution = new EvenDayDistribution();

        foreach ([1, 7, 30, 31, 184] as $days) {
            foreach ([0, 1, 29, 31, 100, 1_000, 99_999] as $total) {
                $split = $distribution->split($total, $days);

                if ([] === $split) {
                    self::fail('Раскладка пуста.');
                }

                self::assertCount($days, $split);
                self::assertSame($total, array_sum($split), "total {$total} over {$days} days");
                self::assertLessThanOrEqual(1, max($split) - min($split));
            }
        }
    }

    public function testRejectsNegativeTotal(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new EvenDayDistribution())->split(-1, 3);
    }

    public function testRejectsEmptyPeriod(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new EvenDayDistribution())->split(5, 0);
    }
}
