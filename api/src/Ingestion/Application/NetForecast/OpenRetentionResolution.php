<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

final readonly class OpenRetentionResolution
{
    /** @param list<OpenRetentionAttempt> $attempts */
    private function __construct(public ?OpenRetentionAssessment $selected, public array $attempts, public ?string $unavailableReason)
    {
    }

    public static function knownZero(OpenRetentionAssessment $selected): self
    {
        if (null === $selected->quantities || null !== $selected->unavailableReason || 'no_eligible_open' !== $selected->basis
            || 0 !== $selected->quantities->eligibleOpenQuantity || null !== $selected->grossRate || null !== $selected->returnCurve) {
            throw new \InvalidArgumentException('Known zero resolution requires an untrained zero assessment.');
        }

        return new self($selected, [], null);
    }

    /** @param list<OpenRetentionAttempt> $attempts */
    public static function available(OpenRetentionAssessment $selected, array $attempts): self
    {
        self::assertAttempts($attempts);
        if (null === $selected->quantities || null !== $selected->unavailableReason || !\in_array($selected->basis, ['sku', 'account'], true)
            || [] === $attempts || $attempts[array_key_last($attempts)]->assessment !== $selected) {
            throw new \InvalidArgumentException('Selected open retention must be its last successful attempt.');
        }
        foreach (\array_slice($attempts, 0, -1) as $attempt) {
            if (null !== $attempt->assessment->quantities || null === $attempt->assessment->unavailableReason) {
                throw new \InvalidArgumentException('Open retention cannot continue after a successful attempt.');
            }
        }

        return new self($selected, $attempts, null);
    }

    /** @param list<OpenRetentionAttempt> $attempts */
    public static function unavailable(string $reason, array $attempts = []): self
    {
        self::assertAttempts($attempts);
        if ('' === trim($reason) || ([] !== $attempts && (2 !== \count($attempts) || 'open_retention_unavailable' !== $reason))) {
            throw new \InvalidArgumentException('Unavailable open retention requires a reason and both failed attempts.');
        }
        foreach ($attempts as $attempt) {
            if (null !== $attempt->assessment->quantities || null === $attempt->assessment->unavailableReason) {
                throw new \InvalidArgumentException('Unavailable open retention cannot contain a successful attempt.');
            }
        }

        return new self(null, $attempts, $reason);
    }

    /** @param list<OpenRetentionAttempt> $attempts */
    private static function assertAttempts(array $attempts): void
    {
        if (!array_is_list($attempts) || \count($attempts) > 2) {
            throw new \InvalidArgumentException('Open retention attempts must be a list of at most two scopes.');
        }
        foreach ($attempts as $index => $attempt) {
            if ((0 === $index) === (null === $attempt->marketplaceSku)) {
                throw new \InvalidArgumentException('Open retention attempts must use SKU then own account.');
            }
        }
    }
}
