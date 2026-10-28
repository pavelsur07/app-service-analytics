<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

final readonly class NetTargetReadinessRow
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
        if ('' === trim($orderNumber) || '' === trim($marketplaceSku) || '' === trim($coveragePolicy)
            || 0 !== $deliveryOrigin->getOffset() || 0 !== $coverageFrom->getOffset() || 0 !== $coverageThrough->getOffset()
            || (null !== $deepCompletedAt && 0 !== $deepCompletedAt->getOffset()) || $coverageFrom > $coverageThrough) {
            throw new \InvalidArgumentException('Invalid target readiness identity or UTC bounds.');
        }
    }
}
