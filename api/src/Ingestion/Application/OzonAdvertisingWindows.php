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
