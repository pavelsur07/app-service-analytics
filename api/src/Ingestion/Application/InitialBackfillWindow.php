<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

/**
 * Ступень 1 первичной загрузки при онбординге (ADR-021): текущий
 * календарный месяц, сразу, вперёд остальных ступеней.
 *
 * Месяц, а не скользящие 30 дней: продукт контролирует план-факт,
 * а план-факт живёт календарным месяцем. Клиент, подключившийся второго
 * числа, при скользящем окне получил бы текущий месяц без единого
 * закрытого месяца рядом — то есть цифру, которую не с чем сравнить.
 *
 * Сообщения загрузки устроены по одному бизнес-дню на сообщение,
 * поэтому «месяц» здесь — список дней, а не диапазон.
 */
final readonly class InitialBackfillWindow
{
    /** ADR-009: бизнес-дата в часовом поясе площадки, не в UTC. */
    private const string TIMEZONE = 'Europe/Moscow';

    private function __construct()
    {
    }

    /**
     * @return list<string> даты Y-m-d по возрастанию, включая день подключения
     */
    public static function businessDates(\DateTimeImmutable $connectedAt): array
    {
        $today = $connectedAt->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $cursor = $today->modify('first day of this month');

        $dates = [];
        while ($cursor->format('Y-m-d') <= $today->format('Y-m-d')) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }

        return $dates;
    }
}
