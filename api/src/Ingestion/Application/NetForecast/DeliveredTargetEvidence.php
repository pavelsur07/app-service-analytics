<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;

/**
 * Trusted current-RR assessment supplied by the future target reader, not raw source proof.
 * Complete input asserts reconciled D (known R already removed), known outcomes/history,
 * and a proved previous-known -> delivered origin with unambiguous order/SKU allocation.
 * Unknown/initial-backfilled delivery, conflicting history or missing coverage is incomplete.
 * A zero must be positively established; an absent source row is not zero.
 */
final readonly class DeliveredTargetEvidence
{
    public function __construct(
        public NetTrainingContext $context,
        public string $orderNumber,
        public string $marketplaceSku,
        public ?int $currentD,
        public ?\DateTimeImmutable $deliveryOrigin,
        public bool $inputComplete,
        public ?string $unavailableReason = null,
        public ?DeliveredTargetReadiness $readiness = null,
    ) {
        if ('' === trim($orderNumber) || '' === trim($marketplaceSku)
            || (null !== $currentD && $currentD < 0)
            || (null !== $deliveryOrigin && 0 !== $deliveryOrigin->getOffset())
            || (null !== $unavailableReason && '' === trim($unavailableReason))) {
            throw new \InvalidArgumentException('Invalid delivered target evidence.');
        }
    }
}
