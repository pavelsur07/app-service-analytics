<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\Localization;

use OpenApi\Attributes as OA;

/**
 * Доли — basis points (10000 = 100%), деньги — минорные единицы в currency;
 * фронтенд форматирует, но не считает (CLAUDE.md §10). null у доли
 * и логистики на штуку — мало данных или ещё нет начислений.
 */
#[OA\Schema(required: [
    'quantity', 'clusteredQuantity', 'localQuantity', 'nonlocalQuantity', 'localShareBps',
    'chargedQuantity', 'chargedShareBps', 'localForwardCostPerUnitMinor',
    'nonlocalForwardCostPerUnitMinor', 'reverseCostMinor', 'currency', 'sufficientData',
])]
final readonly class LocalizationMetricsResponse
{
    public function __construct(
        public int $quantity,
        public int $clusteredQuantity,
        public int $localQuantity,
        public int $nonlocalQuantity,
        public ?int $localShareBps,
        public int $chargedQuantity,
        public ?int $chargedShareBps,
        public ?int $localForwardCostPerUnitMinor,
        public ?int $nonlocalForwardCostPerUnitMinor,
        public ?int $reverseCostMinor,
        public ?string $currency,
        public bool $sufficientData,
    ) {
    }
}
