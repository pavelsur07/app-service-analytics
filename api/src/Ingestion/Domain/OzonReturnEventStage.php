<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/** Доказанная семантика точной buyer return reason (ADR-019). */
enum OzonReturnEventStage: string
{
    case HandoverRefusal = 'HANDOVER_REFUSAL';
    case PickupExpired = 'PICKUP_EXPIRED';
    case DeliveryFailed = 'DELIVERY_FAILED';
    case Cancelled = 'CANCELLED';
}
