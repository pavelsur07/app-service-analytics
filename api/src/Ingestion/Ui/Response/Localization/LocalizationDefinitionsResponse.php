<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\Localization;

use OpenApi\Attributes as OA;

/**
 * Правила расчёта — частью ответа (объяснимость расчёта, CLAUDE.md):
 * клиент сверяет цифру с кабинетом и должен видеть, как она получена.
 */
#[OA\Schema(required: ['minQuantity', 'rounding', 'forwardFeeTypeIds', 'reverseFeeTypeIds'])]
final readonly class LocalizationDefinitionsResponse
{
    /**
     * @param list<int> $forwardFeeTypeIds
     * @param list<int> $reverseFeeTypeIds
     */
    public function __construct(
        public int $minQuantity,
        #[OA\Property(enum: ['half_away_from_zero'])]
        public string $rounding,
        public array $forwardFeeTypeIds,
        public array $reverseFeeTypeIds,
    ) {
    }
}
