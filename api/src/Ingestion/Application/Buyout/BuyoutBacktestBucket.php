<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Buyout;

/** Ошибки прогноза и наивной оценки в bps по одной корзине горизонта. */
final readonly class BuyoutBacktestBucket
{
    public function __construct(
        public string $label,
        public int $cohorts,
        public int $forecastCount,
        public ?int $forecastMaeBps,
        public ?int $forecastBiasBps,
        public int $naiveCount,
        public ?int $naiveMaeBps,
        public ?int $naiveBiasBps,
    ) {
    }
}
