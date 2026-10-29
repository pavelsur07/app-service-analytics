<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\ValueObject;

/** Daily report proof for a terminal cohort's seven-day observation lag. */
final class NetForwardTerminalCoverage
{
    /** @param list<array<array-key, mixed>> $receipts */
    public static function isComplete(\DateTimeImmutable $terminal, array $receipts): bool
    {
        $msk = new \DateTimeZone('Europe/Moscow');
        $from = $terminal->setTimezone($msk)->setTime(0, 0);
        $to = $terminal->modify('+7 days')->setTimezone($msk)->setTime(0, 0);
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $success = $failure = $timely = $timelyFailure = null;
            foreach ($receipts as $receipt) {
                if ('ozon_returns_list' !== ($receipt['report_type'] ?? null)
                    || !\is_string($receipt['date_from'] ?? null)
                    || !\is_string($receipt['date_to'] ?? null)
                    || $receipt['date_from'] !== $date
                    || $receipt['date_to'] !== $date
                    || !\is_string($receipt['marked_at'] ?? null)) {
                    continue;
                }
                $at = new \DateTimeImmutable($receipt['marked_at'], new \DateTimeZone('UTC'));
                if ('failed' === ($receipt['status'] ?? null)) {
                    $failure = null === $failure ? $at : max($failure, $at);
                    if ($at <= $day->modify('+2 days')) {
                        $timelyFailure = null === $timelyFailure ? $at : max($timelyFailure, $at);
                    }
                } elseif ('completed' === ($receipt['status'] ?? null) && 'live_polling' === ($receipt['capture_origin'] ?? null) && 'accepted_state_v1' === ($receipt['capture_version'] ?? null)) {
                    $success = null === $success ? $at : max($success, $at);
                    if ($at >= $day->modify('+1 day') && $at <= $day->modify('+2 days')) {
                        $timely = null === $timely ? $at : max($timely, $at);
                    }
                }
            }
            if (null === $success || null === $timely || (null !== $failure && $failure >= $success) || (null !== $timelyFailure && $timelyFailure >= $timely)) {
                return false;
            }
        }

        return true;
    }
}
