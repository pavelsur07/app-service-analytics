<?php

declare(strict_types=1);

namespace App\SalesPlanning\Domain;

/**
 * Равномерная раскладка штук периода по дням (ADR-040 п. 7).
 *
 * Штуки целые: каждый день получает `floor(total / days)`, остаток —
 * по одной штуке первым дням. Сумма дней строго равна введённому:
 * потерянная или лишняя штука сделала бы итог плана не тем, что ввёл
 * человек.
 */
final readonly class EvenDayDistribution
{
    /**
     * @return list<int> штуки по дням, по порядку дней периода
     */
    public function split(int $total, int $days): array
    {
        if ($total < 0) {
            throw new \InvalidArgumentException('Штук за период не может быть меньше нуля.');
        }

        if ($days < 1) {
            throw new \InvalidArgumentException('В периоде должен быть хотя бы один день.');
        }

        $base = intdiv($total, $days);
        $remainder = $total % $days;

        $quantities = [];
        for ($day = 0; $day < $days; ++$day) {
            $quantities[] = $base + ($day < $remainder ? 1 : 0);
        }

        return $quantities;
    }
}
