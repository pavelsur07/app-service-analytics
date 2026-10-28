<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

final readonly class NetTargetPartRow
{
    public function __construct(
        public string $sourceRowId,
        public ?string $postingNumber,
        public int $rawQuantity,
        public ?int $currentD,
        public ?int $knownR,
        public ?int $t1,
        public ?int $t2,
        public ?int $p,
        public ?int $eligibleOpenPre,
        public ?int $eligibleOpenPost,
        public ?int $unknownQuantity,
        public ?\DateTimeImmutable $deliveryOrigin,
        public ?NetTargetReadinessRow $readiness,
        public NetTargetSourceTrace $trace,
        public bool $inputComplete,
        public ?string $unavailableReason,
    ) {
        if (null !== $deliveryOrigin && 0 !== $deliveryOrigin->getOffset()) {
            throw new \InvalidArgumentException('Target timestamps must be UTC.');
        }
    }
}
