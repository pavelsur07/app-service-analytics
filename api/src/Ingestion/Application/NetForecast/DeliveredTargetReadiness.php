<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;

/**
 * Target-bound assertions from the future reader, not receipts or historical visibility proof.
 * daily_complete_receipts_v1 includes daily returns coverage through H+L, complete postings
 * and monotone history; deepCompletedAt attests a deep window covering origin..H.
 * All underlying accepted observations/receipts must be visible in this same current RR.
 * Full MSK-day witnesses may make real readiness later than the exact H+L boundary.
 */
final readonly class DeliveredTargetReadiness
{
    public function __construct(
        public NetTrainingContext $context,
        public string $orderNumber,
        public string $marketplaceSku,
        public \DateTimeImmutable $deliveryOrigin,
        public string $coveragePolicy,
        public \DateTimeImmutable $coverageFrom,
        public \DateTimeImmutable $coverageThrough,
        public ?\DateTimeImmutable $deepCompletedAt,
        public bool $historyComplete,
        public bool $postingsCoverageComplete,
        public bool $returnsCoverageComplete,
    ) {
        if ('' === trim($orderNumber) || '' === trim($marketplaceSku)
            || 0 !== $deliveryOrigin->getOffset() || 0 !== $coverageFrom->getOffset()
            || 0 !== $coverageThrough->getOffset()
            || (null !== $deepCompletedAt && 0 !== $deepCompletedAt->getOffset())
            || $coverageThrough < $coverageFrom) {
            throw new \InvalidArgumentException('Invalid delivered target readiness.');
        }
    }
}
