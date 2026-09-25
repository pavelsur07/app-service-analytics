<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Buyout;

use App\Ingestion\Infrastructure\Query\Buyout\BuyoutDailyRow;

/**
 * Чистый расчёт проверки прогноза (ADR-030): пары «прогноз на дату —
 * итог» и сводка ошибок по горизонту. Без обращения к базе.
 */
final class BuyoutBacktest
{
    /** Горизонт — сколько дней было дню заказа на дату прогноза. */
    public const array HORIZON_BUCKETS = [[1, 2], [3, 5], [6, 9], [10, 15]];

    public const int MAX_HORIZON_DAYS = 15;

    /**
     * @param list<BuyoutDailyRow>          $atAsOf дневной ряд кабинета, посчитанный на дату прогноза
     * @param array<string, BuyoutDailyRow> $final  сегодняшний ряд, ключ — дата заказа
     *
     * @return list<BuyoutBacktestPair>
     */
    public static function pairs(\DateTimeImmutable $asOfDate, array $atAsOf, array $final): array
    {
        $pairs = [];
        foreach ($atAsOf as $row) {
            $truth = $final[$row->date] ?? null;
            if (
                null === $truth
                || 'mature' !== $truth->maturityStatus
                || null === $truth->actualBuyoutRateBps
                || 'preliminary' !== $row->maturityStatus
            ) {
                continue;
            }
            $horizon = (int) (new \DateTimeImmutable($row->date))->diff($asOfDate)->format('%r%a');
            if ($horizon < 1 || $horizon > self::MAX_HORIZON_DAYS) {
                continue;
            }
            $pairs[] = new BuyoutBacktestPair(
                asOfDate: $asOfDate->format('Y-m-d'),
                cohortDate: $row->date,
                horizonDays: $horizon,
                forecastBps: $row->projectedBuyoutRateBps,
                naiveBps: $row->knownBuyoutRateBps,
                actualBps: $truth->actualBuyoutRateBps,
            );
        }

        return $pairs;
    }

    /**
     * @param list<BuyoutBacktestPair> $pairs
     *
     * @return list<BuyoutBacktestBucket> по корзинам горизонта и последней строкой — итог
     */
    public static function summarize(array $pairs): array
    {
        $buckets = [];
        foreach (self::HORIZON_BUCKETS as [$from, $to]) {
            $buckets[] = self::bucket(
                "{$from}–{$to}",
                array_values(array_filter(
                    $pairs,
                    static fn (BuyoutBacktestPair $pair): bool => $pair->horizonDays >= $from && $pair->horizonDays <= $to,
                )),
            );
        }
        $buckets[] = self::bucket('все', $pairs);

        return $buckets;
    }

    /** @param list<BuyoutBacktestPair> $pairs */
    private static function bucket(string $label, array $pairs): BuyoutBacktestBucket
    {
        $forecastErrors = [];
        $naiveErrors = [];
        foreach ($pairs as $pair) {
            if (null !== $pair->forecastBps) {
                $forecastErrors[] = $pair->forecastBps - $pair->actualBps;
            }
            if (null !== $pair->naiveBps) {
                $naiveErrors[] = $pair->naiveBps - $pair->actualBps;
            }
        }

        return new BuyoutBacktestBucket(
            label: $label,
            cohorts: \count($pairs),
            forecastCount: \count($forecastErrors),
            forecastMaeBps: self::meanAbsolute($forecastErrors),
            forecastBiasBps: self::mean($forecastErrors),
            naiveCount: \count($naiveErrors),
            naiveMaeBps: self::meanAbsolute($naiveErrors),
            naiveBiasBps: self::mean($naiveErrors),
        );
    }

    /** @param list<int> $errors */
    private static function meanAbsolute(array $errors): ?int
    {
        return self::mean(array_map(abs(...), $errors));
    }

    /** @param list<int> $errors */
    private static function mean(array $errors): ?int
    {
        if ([] === $errors) {
            return null;
        }

        return (int) round(array_sum($errors) / \count($errors));
    }
}
