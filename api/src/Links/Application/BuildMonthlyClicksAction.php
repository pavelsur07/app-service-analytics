<?php

declare(strict_types=1);

namespace App\Links\Application;

use App\Links\Infrastructure\Query\MonthlyClicksQuery;

final readonly class BuildMonthlyClicksAction
{
    public function __construct(
        private MonthlyClicksQuery $clicks,
    ) {
    }

    public function __invoke(
        string $linkId,
        string $month,
        \DateTimeImmutable $now,
    ): ?MonthlyClicks {
        $period = MonthPeriod::fromString($month, $now);
        if (!$this->clicks->linkExists($linkId)) {
            return null;
        }

        $counts = [];
        foreach ($this->clicks->fetch($linkId, $period->start, $period->endExclusive) as $row) {
            $counts[$row->date] = $row->clicks;
        }

        $items = [];
        for ($day = $period->start; $day < $period->endExclusive; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $items[] = ['date' => $date, 'clicks' => $counts[$date] ?? 0];
        }

        return new MonthlyClicks($linkId, $period->value, $items);
    }
}
