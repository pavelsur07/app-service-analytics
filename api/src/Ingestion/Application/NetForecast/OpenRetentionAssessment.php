<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\GrossProbability;
use App\Ingestion\Domain\ValueObject\ObservedReturnCurve;
use App\Ingestion\Domain\ValueObject\OpenRetainedQuantities;
use App\Ingestion\Domain\ValueObject\ReturnProbability;

final readonly class OpenRetentionAssessment
{
    private function __construct(
        public ?OpenRetainedQuantities $quantities,
        public ?GrossProbability $grossProbability,
        public ?ReturnProbability $cumulativeReturnProbability,
        public ?string $basis,
        public ScopedPreHandoverGrossRate|ScopedPostHandoverGrossRate|null $grossRate,
        public ?ScopedReturnCurve $returnCurve,
        public ?string $unavailableReason,
    ) {
    }

    public static function available(OpenRetainedQuantities $quantities, ?GrossProbability $grossProbability, ?ReturnProbability $cumulativeReturnProbability, string $basis, ScopedPreHandoverGrossRate|ScopedPostHandoverGrossRate|null $grossRate, ?ScopedReturnCurve $returnCurve): self
    {
        if ('no_eligible_open' === $basis) {
            if (null !== $grossProbability || null !== $cumulativeReturnProbability || 0 !== $quantities->eligibleOpenQuantity) {
                throw new \InvalidArgumentException('Invalid zero open retention assessment.');
            }
        } else {
            $curveProbability = null === $cumulativeReturnProbability ? null : $returnCurve?->curve?->cumulativeAt(ObservedReturnCurve::HORIZON)->probability;
            if (!\in_array($basis, ['sku', 'account'], true) || $quantities->eligibleOpenQuantity <= 0
                || null === $grossProbability || null === $grossRate
                || null === $grossRate->probability
                || $grossProbability->numerator !== $grossRate->probability->numerator
                || $grossProbability->denominator !== $grossRate->probability->denominator
                || null !== $grossRate->unavailableReason
                || ('account' === $basis) !== (null === $grossRate->marketplaceSku)
                || (null !== $cumulativeReturnProbability && (null === $returnCurve?->curve
                    || null !== $returnCurve->unavailableReason
                    || null === $curveProbability
                    || $cumulativeReturnProbability->numerator !== $curveProbability->numerator
                    || $cumulativeReturnProbability->denominator !== $curveProbability->denominator))) {
                throw new \InvalidArgumentException('Invalid selected open retention assessment.');
            }
            if (null !== $returnCurve && ($returnCurve->marketplaceSku !== $grossRate->marketplaceSku
                || $returnCurve->companyId !== $grossRate->companyId || $returnCurve->accountId !== $grossRate->accountId
                || $returnCurve->readAt->format('U.u') !== $grossRate->readAt->format('U.u')
                || (null !== $returnCurve->context && null !== $grossRate->context
                    && ($returnCurve->context->fingerprint() !== $grossRate->context->fingerprint()
                        || $returnCurve->context->epoch->start->format('U.u') !== $grossRate->context->epoch->start->format('U.u')
                        || $returnCurve->context->epoch->end?->format('U.u') !== $grossRate->context->epoch->end?->format('U.u'))))) {
                throw new \InvalidArgumentException('Open retention sources must use the same selected scope.');
            }
            if ((array) $quantities !== (array) OpenRetainedQuantities::fromProbabilities($quantities->eligibleOpenQuantity, $grossProbability, $cumulativeReturnProbability)) {
                throw new \InvalidArgumentException('Open retention quantities do not match their probabilities.');
            }
        }

        return new self($quantities, $grossProbability, $cumulativeReturnProbability, $basis, $grossRate, $returnCurve, null);
    }

    public static function unavailable(string $reason, ScopedPreHandoverGrossRate|ScopedPostHandoverGrossRate|null $grossRate = null, ?ScopedReturnCurve $returnCurve = null): self
    {
        if ('' === trim($reason)) {
            throw new \InvalidArgumentException('Unavailable open retention needs a reason.');
        }

        return new self(null, null, null, null, $grossRate, $returnCurve, $reason);
    }
}
