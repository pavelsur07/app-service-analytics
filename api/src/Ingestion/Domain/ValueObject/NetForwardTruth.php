<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\ValueObject;

/** Component truth is anchored to actual delivery/terminal observations, never request age. */
final class NetForwardTruth
{
    /**
     * Open input units may resolve into delivered, returned, or terminal outcomes.
     * Rebuild the pre-ClientReturn delivered amount from the complete later frame.
     */
    public static function deliveredBaselineForOpenInput(int $rawQuantity, ?int $currentD, ?int $knownR, ?int $t1, ?int $t2, ?int $p, ?int $openPre, ?int $openPost, ?int $unknown): ?int
    {
        if ($rawQuantity < 0 || null === $currentD || null === $knownR || null === $t1 || null === $t2 || null === $p || null === $openPre || null === $openPost || null === $unknown
            || $currentD < 0 || $knownR < 0 || $t1 < 0 || $t2 < 0 || $p < 0 || $openPre < 0 || $openPost < 0 || $unknown < 0
            || 0 !== $openPre || 0 !== $openPost || 0 !== $unknown) {
            return null;
        }
        $outcomes = [$currentD, $knownR, $t1, $t2, $p, $openPre, $openPost, $unknown];
        $total = 0;
        foreach ($outcomes as $quantity) {
            if ($quantity > \PHP_INT_MAX - $total) {
                return null;
            }
            $total += $quantity;
        }
        if ($total !== $rawQuantity || $currentD > \PHP_INT_MAX - $knownR) {
            return null;
        }

        return $currentD + $knownR;
    }

    /** @param list<NetForwardReturnDelta> $returns */
    public static function delivered(int $remainingAtInput, \DateTimeImmutable $origin, \DateTimeImmutable $inputAt, \DateTimeImmutable $truthAt, array $returns, bool $openAtInput, bool $coverage): NetForwardTruthResult
    {
        $readyAt = $origin->modify('+67 days');
        if ($remainingAtInput < 0 || $truthAt < $inputAt || (!$openAtInput && $origin > $inputAt) || ($openAtInput && $origin <= $inputAt)) {
            return new NetForwardTruthResult('ineligible', null, 'delivery_origin_ambiguous', $readyAt);
        }
        $remaining = $remainingAtInput;
        foreach ($returns as $return) {
            if ($return->visibleAtInput) {
                continue;
            }
            if (!$return->proven || $return->quantity < 0 || $return->capturedAt <= $inputAt || $return->capturedAt > $truthAt) {
                return new NetForwardTruthResult('ineligible', null, 'return_revision_ambiguous', $readyAt);
            }
            if ('Cancellation' === $return->returnType) {
                continue;
            }
            if ($return->capturedAt <= $origin) {
                return new NetForwardTruthResult('ineligible', null, 'return_revision_ambiguous', $readyAt);
            }
            if ($return->capturedAt >= $origin->modify('+60 days')) {
                continue;
            }
            if ($return->quantity > $remaining) {
                return new NetForwardTruthResult('ineligible', null, 'contradictory_quantity', $readyAt);
            }
            $remaining -= $return->quantity;
        }
        if ($truthAt < $readyAt || !$coverage) {
            return new NetForwardTruthResult('pending', null, $truthAt < $readyAt ? 'target_horizon_not_ready' : 'returns_coverage_missing', $readyAt);
        }

        return new NetForwardTruthResult('ready', $remaining, null, $readyAt);
    }

    public static function terminal(\DateTimeImmutable $terminalAt, \DateTimeImmutable $truthAt, bool $coverage): NetForwardTruthResult
    {
        $readyAt = $terminalAt->modify('+7 days');
        if ($truthAt < $readyAt || !$coverage) {
            return new NetForwardTruthResult('pending', null, $truthAt < $readyAt ? 'terminal_lag_not_ready' : 'returns_coverage_missing', $readyAt);
        }

        return new NetForwardTruthResult('ready', 0, null, $readyAt);
    }
}
