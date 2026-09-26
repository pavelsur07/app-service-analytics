<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\DeliverySpeed;

use OpenApi\Attributes as OA;

/**
 * Длительности — секунды; фронтенд форматирует в дни. null у медианы —
 * меньше порога отправлений (definitions.minPostings).
 */
#[OA\Schema(required: [
    'postings', 'arrivedPostings', 'rescanArrivedPostings', 'localArrivedPostings', 'nonlocalArrivedPostings',
    'medianDeliverySeconds', 'p90DeliverySeconds', 'medianAssemblySeconds', 'medianTransitSeconds',
    'medianLocalSeconds', 'medianNonlocalSeconds', 'sufficientData',
])]
final readonly class DeliverySpeedMetricsResponse
{
    public function __construct(
        public int $postings,
        public int $arrivedPostings,
        public int $rescanArrivedPostings,
        public int $localArrivedPostings,
        public int $nonlocalArrivedPostings,
        public ?int $medianDeliverySeconds,
        public ?int $p90DeliverySeconds,
        public ?int $medianAssemblySeconds,
        public ?int $medianTransitSeconds,
        public ?int $medianLocalSeconds,
        public ?int $medianNonlocalSeconds,
        public bool $sufficientData,
    ) {
    }
}
