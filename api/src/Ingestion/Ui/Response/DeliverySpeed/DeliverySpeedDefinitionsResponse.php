<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\DeliverySpeed;

use OpenApi\Attributes as OA;

/**
 * Правила расчёта — частью ответа (объяснимость расчёта, CLAUDE.md):
 * клиент сверяет цифру с кабинетом и должен видеть, как она получена.
 */
#[OA\Schema(required: [
    'startEvent', 'arrivalEvent', 'estimate', 'tickStepSeconds', 'rescanStepSeconds', 'tickWindowDays',
    'liveObservationMaxLagHours', 'maturityLagDays', 'minPostings',
])]
final readonly class DeliverySpeedDefinitionsResponse
{
    public function __construct(
        #[OA\Property(enum: ['in_process_at'])]
        public string $startEvent,
        #[OA\Property(enum: ['pickup_point_or_delivered'])]
        public string $arrivalEvent,
        #[OA\Property(enum: ['midpoint_of_poll_gap'])]
        public string $estimate,
        public int $tickStepSeconds,
        public int $rescanStepSeconds,
        public int $tickWindowDays,
        public int $liveObservationMaxLagHours,
        public int $maturityLagDays,
        public int $minPostings,
    ) {
    }
}
