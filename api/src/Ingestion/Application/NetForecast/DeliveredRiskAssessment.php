<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\DeliveredRiskQuantities;
use App\Ingestion\Domain\ValueObject\ReturnProbability;

final readonly class DeliveredRiskAssessment
{
    /** @param list<DeliveredRiskAttempt> $attempts */
    private function __construct(
        public ?DeliveredRiskQuantities $quantities,
        public ?ReturnProbability $probability,
        public ?int $ageDays,
        public ?string $basis,
        public array $attempts,
        public ?string $unavailableReason,
    ) {
    }

    /** @param list<DeliveredRiskAttempt> $attempts */
    public static function available(DeliveredRiskQuantities $quantities, ?ReturnProbability $probability, ?int $ageDays, string $basis, array $attempts): self
    {
        if (!\in_array($basis, ['no_remaining_delivery', 'mature_target', 'sku', 'account'], true)
            || (null === $probability) !== ('no_remaining_delivery' === $basis)) {
            throw new \InvalidArgumentException('Invalid delivered risk basis.');
        }

        return new self($quantities, $probability, $ageDays, $basis, $attempts, null);
    }

    /** @param list<DeliveredRiskAttempt> $attempts */
    public static function unavailable(string $reason, ?int $ageDays = null, array $attempts = []): self
    {
        if ('' === trim($reason)) {
            throw new \InvalidArgumentException('Unavailable delivered risk needs a reason.');
        }

        return new self(null, null, $ageDays, null, $attempts, $reason);
    }
}
