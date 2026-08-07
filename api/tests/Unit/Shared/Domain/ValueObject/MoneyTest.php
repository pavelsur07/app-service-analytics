<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\ValueObject;

use App\Shared\Domain\ValueObject\AllocationRule;
use App\Shared\Domain\ValueObject\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testAllocationDistributesRemainderAndSumsBackToTheWhole(): void
    {
        $total = Money::ofMinor(10_000, 'RUB');

        $parts = $total->allocate([1, 1, 1], AllocationRule::RemainderToFirst);

        $minorAmounts = array_map(static fn (Money $part): int => $part->minorAmount(), $parts);

        self::assertSame([3334, 3333, 3333], $minorAmounts);
        self::assertSame($total->minorAmount(), array_sum($minorAmounts));

        foreach ($parts as $part) {
            self::assertSame('RUB', $part->currency());
        }
    }
}

// Проба фильтров конвейера: коммит только в api (критерий 2).
