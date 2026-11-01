<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain;

final readonly class ResearchCalendar
{
    private const array Months = ['янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];

    /** @param list<PeriodRange> $ranges */
    public function preview(array $ranges, bool $includeCurrentMonth, \DateTimeImmutable $at): CalendarPreview
    {
        if ([] === $ranges || \count($ranges) > 24) {
            throw new \InvalidArgumentException('period_limit');
        }
        $today = $at->setTimezone(new \DateTimeZone(CalendarPreview::Timezone))->setTime(0, 0);
        $current = $today->format('Y-m');
        $periods = [];
        $months = [];
        foreach ($ranges as $range) {
            $first = new \DateTimeImmutable($range->fromMonth.'-01', new \DateTimeZone(CalendarPreview::Timezone));
            $last = new \DateTimeImmutable($range->toMonth.'-01', new \DateTimeZone(CalendarPreview::Timezone));
            $length = ((int) $last->format('Y') - (int) $first->format('Y')) * 12 + (int) $last->format('n') - (int) $first->format('n') + 1;
            if ($length > 24) {
                throw new \InvalidArgumentException('month_limit');
            }
            $periods[$range->key()] = $range;
            for ($month = $first; $month <= $last; $month = $month->modify('+1 month')) {
                $key = $month->format('Y-m');
                if ($key > $current || ($key === $current && (!$includeCurrentMonth || '01' === $today->format('d')))) {
                    throw new \InvalidArgumentException('unavailable_month');
                }
                $partial = $key === $current;
                $end = $partial ? $today->modify('-1 day') : $month->modify('last day of this month');
                $months[$key] = new MonthSlice($key, $month->format('Y-m-d'), $end->format('Y-m-d'), (int) $end->format('j'), $partial);
                if (\count($months) > 24) {
                    throw new \InvalidArgumentException('month_limit');
                }
            }
        }
        ksort($months);

        return new CalendarPreview(array_values($periods), array_values($months), $today->format('Y-m-d'), $this->summary(array_keys($months)));
    }

    public function lastClosedMonths(int $count, \DateTimeImmutable $at): PeriodRange
    {
        if (!\in_array($count, [3, 6, 12], true)) {
            throw new \InvalidArgumentException('invalid_shortcut');
        }
        $first = $at->setTimezone(new \DateTimeZone(CalendarPreview::Timezone))->modify('first day of this month')->setTime(0, 0);

        return new PeriodRange($first->modify('-'.$count.' months')->format('Y-m'), $first->modify('-1 month')->format('Y-m'));
    }

    /** @return list<PeriodRange> */
    public function withPreviousYear(PeriodRange $range): array
    {
        $shift = static fn (string $month): string => (new \DateTimeImmutable($month.'-01'))->modify('-1 year')->format('Y-m');

        return [$range, new PeriodRange($shift($range->fromMonth), $shift($range->toMonth), $range->name)];
    }

    /** @param list<string> $months */
    private function summary(array $months): string
    {
        $blocks = [];
        $start = $months[0];
        $previous = $start;
        foreach (\array_slice($months, 1) as $month) {
            if ((new \DateTimeImmutable($previous.'-01'))->modify('+1 month')->format('Y-m') !== $month) {
                $blocks[] = $this->span($start, $previous);
                $start = $month;
            }
            $previous = $month;
        }
        $blocks[] = $this->span($start, $previous);

        return implode(', ', $blocks);
    }

    private function span(string $from, string $to): string
    {
        $label = static fn (string $month): string => self::Months[(int) substr($month, 5, 2) - 1];
        if ($from === $to) {
            return $label($from).' '.substr($from, 0, 4);
        }
        if (substr($from, 0, 4) === substr($to, 0, 4)) {
            return $label($from).'–'.$label($to).' '.substr($to, 0, 4);
        }

        return $label($from).' '.substr($from, 0, 4).'–'.$label($to).' '.substr($to, 0, 4);
    }
}
