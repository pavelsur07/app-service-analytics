<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

/**
 * Периоды загрузки рекламы (ADR-026 п. 4) — одно место для планировщика
 * и сценария подключения ключа.
 *
 * Любой период запрашивается кусками не длиннее 30 дней: синхронные
 * методы Performance API проверены на диапазоне в 30 дней, длиннее — нет.
 * Куски режутся от конца периода: первым идёт кусок с сегодняшним днём,
 * самый нужный клиенту.
 */
final class OzonAdvertisingWindows
{
    /** Дни Performance API — московские (ADR-026). */
    public const string TIMEZONE = 'Europe/Moscow';

    public const int CHUNK_DAYS = 30;

    /**
     * Кампаний в одном запросе `products/sku`. Проверено разведкой на пяти;
     * десять — тот же потолок, что у асинхронного отчёта.
     */
    public const int SKU_CAMPAIGNS_PER_REQUEST = 10;

    /** Первичная загрузка при подключении ключа — 12 месяцев назад. */
    public const int INITIAL_MONTHS = 12;

    /**
     * Сегодняшний день по Москве, полночь.
     */
    public static function today(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $local = $now->setTimezone(new \DateTimeZone(self::TIMEZONE));

        return new \DateTimeImmutable($local->format('Y-m-d'), new \DateTimeZone(self::TIMEZONE));
    }

    /**
     * Последние `$days` дней, сегодня включительно, кусками.
     *
     * @return list<array{from: string, to: string}>
     */
    public static function lastDays(\DateTimeImmutable $today, int $days): array
    {
        if ($days < 1) {
            throw new \InvalidArgumentException('Advertising window must be at least one day.');
        }

        return self::chunks($today->modify('-'.($days - 1).' days'), $today);
    }

    /**
     * Первичная загрузка: 12 месяцев назад от дня подключения, сегодня
     * включительно, кусками.
     *
     * @return list<array{from: string, to: string}>
     */
    public static function initial(\DateTimeImmutable $today): array
    {
        return self::chunks($today->modify('-'.self::INITIAL_MONTHS.' months'), $today);
    }

    /**
     * Головной кусок — тот, что кончается в последние `CHUNK_DAYS` дней.
     * Такой кусок один в каждом тике, рескане и первичной загрузке: следующий
     * за ним кончается на `CHUNK_DAYS` дней раньше. Признак переживает
     * задержку очереди до `CHUNK_DAYS - 1` дней — головной кусок стареет,
     * но остальные остаются старше его.
     */
    public static function isHeadChunk(\DateTimeImmutable $to, \DateTimeImmutable $today): bool
    {
        return $to->format('Y-m-d') >= $today->modify('-'.(self::CHUNK_DAYS - 1).' days')->format('Y-m-d');
    }

    /**
     * Дни куска, которые отдаёт `products/sku`: вчера и сегодня,
     * от нового к старому.
     *
     * @return list<\DateTimeImmutable>
     */
    public static function skuDays(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $today): array
    {
        $days = [];
        foreach ([$today, $today->modify('-1 day')] as $day) {
            if ($day->format('Y-m-d') >= $from->format('Y-m-d') && $day->format('Y-m-d') <= $to->format('Y-m-d')) {
                $days[] = $day;
            }
        }

        return $days;
    }

    /**
     * Период SKU-отчёта для куска: кусок без вчера и сегодня — их отдаёт
     * `products/sku`. `null`, если в куске других дней нет.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}|null
     */
    public static function skuReportPeriod(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $today): ?array
    {
        $dayBeforeYesterday = $today->modify('-2 days');
        $end = $to->format('Y-m-d') > $dayBeforeYesterday->format('Y-m-d') ? $dayBeforeYesterday : $to;

        return $end->format('Y-m-d') < $from->format('Y-m-d') ? null : [$from, $end];
    }

    /**
     * Период `[from, to]` включительно кусками, от нового к старому.
     *
     * @return list<array{from: string, to: string}>
     */
    public static function between(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return self::chunks($from, $to);
    }

    /**
     * @return list<array{from: string, to: string}>
     */
    private static function chunks(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $chunks = [];
        $end = $to;
        while ($end >= $from) {
            $start = $end->modify('-'.(self::CHUNK_DAYS - 1).' days');
            if ($start < $from) {
                $start = $from;
            }

            $chunks[] = ['from' => $start->format('Y-m-d'), 'to' => $end->format('Y-m-d')];
            $end = $start->modify('-1 day');
        }

        return $chunks;
    }
}
