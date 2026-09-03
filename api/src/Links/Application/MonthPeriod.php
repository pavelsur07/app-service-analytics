<?php

declare(strict_types=1);

namespace App\Links\Application;

final readonly class MonthPeriod
{
    private function __construct(
        public string $value,
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $endExclusive,
        public \DateTimeImmutable $lastIncludedDay,
    ) {
    }

    public static function fromString(string $month, \DateTimeImmutable $now): self
    {
        if (1 !== preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/D', $month)) {
            throw new \InvalidArgumentException('month_invalid');
        }

        $utc = new \DateTimeZone('UTC');
        $today = $now->setTimezone($utc)->setTime(0, 0);
        $currentMonth = $today->format('Y-m');
        if ($month > $currentMonth) {
            throw new \InvalidArgumentException('month_in_future');
        }

        $start = new \DateTimeImmutable($month.'-01 00:00:00', $utc);
        $endExclusive = $month === $currentMonth
            ? $today->modify('+1 day')
            : $start->modify('first day of next month');

        return new self(
            $month,
            $start,
            $endExclusive,
            $endExclusive->modify('-1 day'),
        );
    }
}
