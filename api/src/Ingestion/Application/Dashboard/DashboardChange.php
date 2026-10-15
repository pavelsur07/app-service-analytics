<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Dashboard;

/**
 * Изменение показателя к периоду сравнения — целочисленно, без float
 * (CLAUDE.md §3: промежуточные значения тоже).
 *
 * Два вида, и путать их нельзя: для сумм и штук — относительное
 * изменение («выросли на 12,5 %»), для показателей, которые сами
 * процент (выкуп, ДРР), — разница в процентных пунктах. «ДРР вырос
 * на 20 %» при переходе с 10 % на 12 % — правда, которую читают как
 * «стал 30 %».
 *
 * Округление — половина от нуля, как у `ROUND(numeric)` в PostgreSQL,
 * которым посчитаны сами показатели: одно правило на весь экран.
 */
final class DashboardChange
{
    private function __construct()
    {
    }

    /**
     * Относительное изменение в базисных пунктах: 1250 — «+12,5 %».
     * NULL, когда сравнивать не с чем: в периоде сравнения ноль
     * или показателя нет.
     */
    public static function relativeBps(?int $current, ?int $previous): ?int
    {
        if (null === $current || null === $previous || 0 === $previous) {
            return null;
        }

        return self::divideRounded(($current - $previous) * 10_000, abs($previous));
    }

    /**
     * Разница процентов в базисных пунктах — сотых долях процентного
     * пункта: 150 — «+1,5 п.п.».
     */
    public static function pointsBps(?int $currentBps, ?int $previousBps): ?int
    {
        if (null === $currentBps || null === $previousBps) {
            return null;
        }

        return $currentBps - $previousBps;
    }

    /** Деление с округлением половины от нуля; делитель положительный. */
    private static function divideRounded(int $dividend, int $divisor): int
    {
        $quotient = intdiv($dividend, $divisor);
        $remainder = abs($dividend % $divisor);
        if (2 * $remainder >= $divisor) {
            $quotient += $dividend < 0 ? -1 : 1;
        }

        return $quotient;
    }
}
