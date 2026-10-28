<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

/** Preserves a whole attempted scope, even when its source rows are missing. */
final readonly class OpenRetentionAttempt
{
    public function __construct(public ?string $marketplaceSku, public OpenRetentionAssessment $assessment)
    {
        if ((null !== $marketplaceSku && '' === trim($marketplaceSku)) || 'no_eligible_open' === $assessment->basis
            || ('sku' === $assessment->basis && null === $marketplaceSku)
            || ('account' === $assessment->basis && null !== $marketplaceSku)) {
            throw new \InvalidArgumentException('Invalid open retention attempt scope.');
        }
        $first = null;
        foreach ([$assessment->grossRate, $assessment->returnCurve] as $input) {
            if (null === $input) {
                continue;
            }
            if ($input->marketplaceSku !== $marketplaceSku || (null !== $input->source && $input->source->marketplaceSku !== $marketplaceSku)) {
                throw new \InvalidArgumentException('Open retention attempt source belongs to another scope.');
            }
            $input->context?->assertScope($input->companyId, $input->accountId);
            if (null !== $input->context && $input->context->readAt->format('U.u') !== $input->readAt->format('U.u')) {
                throw new \InvalidArgumentException('Open retention attempt context has another read time.');
            }
            if (null !== $first && ($input->companyId !== $first->companyId || $input->accountId !== $first->accountId
                || $input->readAt->format('U.u') !== $first->readAt->format('U.u')
                || (null !== $input->context && null !== $first->context
                    && ($input->context->fingerprint() !== $first->context->fingerprint()
                        || $input->context->epoch->start->format('U.u') !== $first->context->epoch->start->format('U.u')
                        || $input->context->epoch->end?->format('U.u') !== $first->context->epoch->end?->format('U.u'))))) {
                throw new \InvalidArgumentException('Open retention attempt must use one whole training context.');
            }
            $first = $input;
        }
    }
}
