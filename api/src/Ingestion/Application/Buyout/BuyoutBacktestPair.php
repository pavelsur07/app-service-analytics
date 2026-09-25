<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Buyout;

/** День заказа, незрелый на дату прогноза и зрелый сегодня (ADR-030). */
final readonly class BuyoutBacktestPair
{
    public function __construct(
        public string $asOfDate,
        public string $cohortDate,
        public int $horizonDays,
        public ?int $forecastBps,
        public ?int $naiveBps,
        public int $actualBps,
    ) {
    }
}
