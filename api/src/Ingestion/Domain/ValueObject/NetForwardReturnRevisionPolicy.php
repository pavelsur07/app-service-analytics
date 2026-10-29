<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\ValueObject;

/** ADR-049 v1 return-chain transitions eligible for forward truth deltas. */
final class NetForwardReturnRevisionPolicy
{
    public static function isProven(?string $previousType, ?string $type, bool $sameIdentity): bool
    {
        if (!$sameIdentity || null === $type || !\in_array($type, ['Cancellation', 'ClientReturn'], true)
            || (null !== $previousType && !\in_array($previousType, ['Cancellation', 'ClientReturn'], true))) {
            return false;
        }

        return null === $previousType || $previousType === $type || ('Cancellation' === $previousType && 'ClientReturn' === $type);
    }
}
