<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\Buyout;

use OpenApi\Attributes as OA;

#[OA\Schema(required: ['date', 'actualBuyoutRateBps', 'projectedBuyoutRateBps', 'resolutionRateBps', 'orderedQuantity', 'resolvedQuantity', 'projectedBuyoutQuantity', 'maturityStatus', 'inFlightRateBps', 'unestimatedRateBps'])]
final readonly class BuyoutDailyPointResponse
{
    public function __construct(
        public string $date,
        public ?int $actualBuyoutRateBps,
        public ?int $projectedBuyoutRateBps,
        public ?int $resolutionRateBps,
        public int $orderedQuantity,
        public int $resolvedQuantity,
        public ?int $projectedBuyoutQuantity,
        #[OA\Property(description: 'ADR-029: факт есть только у зрелой точки', enum: ['mature', 'preliminary'])]
        public string $maturityStatus,
        #[OA\Property(description: 'Доля количества в доставке, bps')]
        public ?int $inFlightRateBps,
        #[OA\Property(description: 'Доля заказанного количества без оценки прогноза (ADR-031, ADR-033), bps; прогноз построен по остальным')]
        public ?int $unestimatedRateBps,
    ) {
    }
}
