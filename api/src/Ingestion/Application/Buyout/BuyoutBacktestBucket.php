<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Buyout;

/**
 * Ошибки в bps по одной корзине горизонта. forecast* — по всем парам
 * с прогнозом; comparable* — только по парам, где есть и прогноз, и
 * наивная оценка: сравнивать их можно лишь на одной выборке.
 */
final readonly class BuyoutBacktestBucket
{
    public function __construct(
        public string $label,
        public int $cohorts,
        public int $forecastCount,
        public ?int $forecastMaeBps,
        public ?int $forecastBiasBps,
        public int $comparableCount,
        public ?int $comparableForecastMaeBps,
        public ?int $comparableNaiveMaeBps,
        public ?int $comparableNaiveBiasBps,
    ) {
    }
}
