<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['date', 'orderedAmountMinor', 'orderedQuantity', 'plannedBuyoutRateBps', 'forecastRevenueMinor', 'actualRevenueMinor'])]
final readonly class SkuForecastFactDayResponse
{
    public function __construct(
        public string $date,
        #[OA\Property(description: 'Заказано в RUB, копейки до СПП')]
        public int $orderedAmountMinor,
        public int $orderedQuantity,
        #[OA\Property(description: 'Плановый процент выкупа, базисные пункты; null без оценки')]
        public ?int $plannedBuyoutRateBps,
        #[OA\Property(description: 'Прогноз по цене каждой строки заказа, копейки до СПП; null без оценки')]
        public ?int $forecastRevenueMinor,
        #[OA\Property(description: 'Уже известный выкуп за вычетом возвратов, копейки до СПП')]
        public int $actualRevenueMinor,
    ) {
    }
}
