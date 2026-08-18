<?php

declare(strict_types=1);

namespace App\PriceMonitoring\Ui\Response;

/**
 * Строка экрана СПП в контракте. Суммы — минорные единицы с валютой
 * рядом (ADR-004): дробные числа в JSON это double, и копейки
 * на них размываются.
 *
 * `null` у любой из сумм означает «не из чего считать», а не ноль:
 * наблюдений по артикулу ещё не приходило либо цены кабинета на тот
 * момент мы не знали. Экран обязан различать эти состояния.
 */
final readonly class PriceOverviewItemResponse
{
    public function __construct(
        public string $marketplaceSku,
        public ?string $name,
        public ?int $sellerPriceMinor,
        public ?int $displayedPriceMinor,
        public ?int $coInvestmentMinor,
        public ?string $currency,
        /** ISO 8601 в UTC; null — наблюдений ещё не было. */
        public ?string $observedAt,
    ) {
    }
}
