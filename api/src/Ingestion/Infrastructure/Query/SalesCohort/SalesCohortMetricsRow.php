<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\SalesCohort;

use App\Shared\Domain\ValueObject\Money;

/** Количества одной SKU за бизнес-день заказа; исходные факты не изменяются. */
final readonly class SalesCohortMetricsRow
{
    public function __construct(
        public string $marketplaceSku,
        public string $date,
        public int $rawOrderedQuantity,
        public int $preHandoverCancelledQuantity,
        public int $effectiveOrderedQuantity,
        /** D уже исключает количество клиентских возвратов R. */
        public int $observedNetSalesQuantity,
        /** Оценка оставшихся продаж по исходным ценам, до расходов. */
        public ?Money $observedRevenue,
        /** Зрелость выкупа по ADR-029, не завершённость возвратного хвоста. */
        public string $maturityStatus,
        public int $inFlightQuantity,
        public ?int $inFlightRateBps,
        /** D + T2 + P, без T1, R и неизвестных исходов. */
        public int $buyoutResolvedQuantity,
        public ?int $knownBuyoutRateBps,
        public ?int $actualBuyoutRateBps,
        /** Текущая методика выкупа, без оценки будущих клиентских возвратов. */
        public ?int $currentProjectedBuyoutQuantity,
        public ?int $currentProjectedBuyoutRateBps,
        public int $unestimatedQuantity,
        public ?int $unestimatedRateBps,
        /** SQL numeric строкой; сохраняется для сумм до конечного округления. */
        public string $knownProjectedBuyoutQuantity,
        public string $knownProjectedEligibleQuantity,
        /** Этап A не моделирует будущие клиентские возвраты. */
        public ?string $expectedNetSalesQuantity = null,
        public string $netForecastUnavailableReason = 'net_forecast_not_available',
    ) {
    }
}
