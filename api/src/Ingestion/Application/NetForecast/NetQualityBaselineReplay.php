<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\NetForecastQuantities;
use App\Ingestion\Domain\ValueObject\NetQualityExact;
use App\Ingestion\Domain\ValueObject\SimpleMatureReturnBaseline;

final readonly class NetQualityBaselineReplay
{
    /** @param list<array<string, mixed>> $rates
     * @return array{selected: ?array{gross: string, returned: string, scope: string}, attempts: list<array<string, mixed>>} */
    public function policy(array $rates, string $sku): array
    {
        $bySku = [];
        foreach ($rates as $rate) {
            $key = $rate['marketplace_sku'] ?? null;
            if (null !== $key && !\is_string($key)) {
                throw new \UnexpectedValueException('Invalid baseline rate scope.');
            }
            if (isset($bySku['s:'.($key ?? '')])) {
                throw new \UnexpectedValueException('Duplicate baseline rate scope.');
            }
            $bySku['s:'.($key ?? '')] = $rate;
        }
        $attempts = [];
        foreach ([$sku, ''] as $key) {
            $row = $bySku['s:'.$key] ?? null;
            $gross = $row['gross_quantity'] ?? null;
            $returned = $row['returned_quantity'] ?? null;
            $postings = $row['postings'] ?? 0;
            if ((null !== $gross && !\is_string($gross)) || (null !== $returned && !\is_string($returned)) || !\is_int($postings) || $postings < 0) {
                throw new \UnexpectedValueException('Invalid baseline sample values.');
            }
            $eligible = true === ($row['input_complete'] ?? null) && null !== $gross && null !== $returned && $postings >= 30 && NetQualityExact::fraction($gross)->compare(NetQualityExact::fraction('100')) >= 0;
            $scope = '' === $key ? 'account' : 'sku';
            $attempts[] = ['scope' => $scope, 'gross' => $gross, 'returned' => $returned, 'postings' => $postings, 'reason' => $eligible ? null : 'insufficient_sample'];
            if ($eligible && null !== $gross && null !== $returned) {
                return ['selected' => ['gross' => $gross, 'returned' => $returned, 'scope' => $scope], 'attempts' => $attempts];
            }
        }

        return ['selected' => null, 'attempts' => $attempts];
    }

    /** @param array{numerator: string, denominator: string}|null $pre
     * @param array{numerator: string, denominator: string}|null $post
     * @param array{gross: string, returned: string, scope: string}|null $rate */
    public function quantity(NetQualityTargetPart $part, ?array $pre, ?array $post, ?array $rate, \DateTimeImmutable $at): ?NetForecastQuantities
    {
        if (!$part->inputComplete || null !== $part->reason || null === $part->currentD || null === $part->openPre || null === $part->openPost || 0 !== $part->unknown) {
            return null;
        }
        $quantity = NetForecastQuantities::zero();
        if ($part->currentD > 0) {
            if (null === $part->origin || $part->origin > $at) {
                return null;
            }
            $age = intdiv($at->getTimestamp() - $part->origin->getTimestamp(), 86400);
            if ($age >= 60) {
                $quantity = NetForecastQuantities::fromComponents((string) $part->currentD, '0', '0', '1', '0', '1');
            } elseif (null !== $rate) {
                $quantity = SimpleMatureReturnBaseline::delivered($part->currentD, $age, $rate['returned'], $rate['gross']);
            } else {
                return null;
            }
        }
        foreach ([[$part->openPre, $pre], [$part->openPost, $post]] as [$units, $gross]) {
            if ($units > 0) {
                if (null === $rate || null === $gross) {
                    return null;
                }
                $quantity = $quantity->plus(SimpleMatureReturnBaseline::open($units, $gross['numerator'], $gross['denominator'], $rate['returned'], $rate['gross']));
            }
        }

        return $quantity;
    }
}
