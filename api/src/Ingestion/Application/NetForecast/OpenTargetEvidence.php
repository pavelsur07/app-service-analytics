<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;

/**
 * Trusted current-RR assessment from the future B09 reader, not raw history proof.
 * Complete input asserts placed, known open units at the stated stage, reconciled
 * without terminal/unknown units or ambiguous order/SKU allocation in this RR.
 * An absent source row cannot prove zero. No SQL producer is supplied here.
 */
final readonly class OpenTargetEvidence
{
    public const string BEFORE_HANDOVER = 'before_handover';

    public function __construct(
        public NetTrainingContext $context,
        public string $orderNumber,
        public string $marketplaceSku,
        public string $stage,
        public ?int $eligibleOpenQuantity,
        public bool $inputComplete,
        public ?string $unavailableReason = null,
    ) {
        if ('' === trim($orderNumber) || '' === trim($marketplaceSku) || '' === trim($stage)
            || (null !== $eligibleOpenQuantity && $eligibleOpenQuantity < 0)
            || (null !== $unavailableReason && '' === trim($unavailableReason))) {
            throw new \InvalidArgumentException('Invalid open target evidence.');
        }
    }
}
