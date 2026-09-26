<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\Localization;

use OpenApi\Attributes as OA;

/**
 * Правила расчёта — частью ответа (объяснимость расчёта, CLAUDE.md):
 * клиент сверяет цифру с кабинетом и должен видеть, как она получена.
 */
#[OA\Schema(required: [
    'localSale', 'periodBasis', 'excludedStatuses', 'reverseIncludesExcluded', 'perUnitBasis',
    'minQuantity', 'rounding', 'forwardFeeTypeIds', 'reverseFeeTypeIds',
])]
final readonly class LocalizationDefinitionsResponse
{
    /**
     * @param list<string> $excludedStatuses  не входят в штуки, доли и прямую логистику
     * @param list<int>    $forwardFeeTypeIds
     * @param list<int>    $reverseFeeTypeIds
     */
    public function __construct(
        /** Локальная продажа — кластер отгрузки совпадает с кластером доставки. */
        #[OA\Property(enum: ['cluster_from_equals_cluster_to'])]
        public string $localSale,
        /** Период — по дате заказа в часовом поясе площадки. */
        #[OA\Property(enum: ['order_date_europe_moscow'])]
        public string $periodBasis,
        public array $excludedStatuses,
        /** Обратная логистика считается и по исключённым: невыкуп у Ozon FBO — тоже cancelled. */
        public bool $reverseIncludesExcluded,
        /** Логистика на штуку — только по штукам, у которых прямая логистика уже начислена. */
        #[OA\Property(enum: ['charged_units_only'])]
        public string $perUnitBasis,
        public int $minQuantity,
        #[OA\Property(enum: ['half_away_from_zero'])]
        public string $rounding,
        public array $forwardFeeTypeIds,
        public array $reverseFeeTypeIds,
    ) {
    }
}
