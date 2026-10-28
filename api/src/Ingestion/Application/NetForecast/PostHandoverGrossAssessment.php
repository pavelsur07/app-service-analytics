<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\GrossDeliveryQuantity;
use App\Ingestion\Domain\ValueObject\GrossProbability;

final readonly class PostHandoverGrossAssessment
{
    /** @param list<ScopedPostHandoverGrossRate> $attempts */
    private function __construct(
        public ?GrossDeliveryQuantity $quantity,
        public ?GrossProbability $probability,
        public ?string $basis,
        public array $attempts,
        public ?string $unavailableReason,
    ) {
    }

    /** @param list<ScopedPostHandoverGrossRate> $attempts */
    public static function available(GrossDeliveryQuantity $quantity, ?GrossProbability $probability, string $basis, array $attempts): self
    {
        if (!\in_array($basis, ['no_eligible_open', 'sku', 'account'], true)
            || (null === $probability) !== ('no_eligible_open' === $basis)
            || ('no_eligible_open' === $basis && 0 !== $quantity->eligibleOpenQuantity)) {
            throw new \InvalidArgumentException('Invalid post-handover gross basis.');
        }

        return new self($quantity, $probability, $basis, $attempts, null);
    }

    /** @param list<ScopedPostHandoverGrossRate> $attempts */
    public static function unavailable(string $reason, array $attempts = []): self
    {
        if ('' === trim($reason)) {
            throw new \InvalidArgumentException('Unavailable post-handover gross needs a reason.');
        }

        return new self(null, null, null, $attempts, $reason);
    }
}
