<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Domain\ValueObject\SimpleMatureReturnBaseline;
use PHPUnit\Framework\TestCase;

final class NetForwardBaselineTest extends TestCase
{
    public function testRemainingUnitsAreNotReducedByKnownReturnsAgain(): void
    {
        $q = SimpleMatureReturnBaseline::delivered(80, 30, '20', '100');
        self::assertSame('72', $q->expectedNetNumerator);
        self::assertSame('1', $q->expectedNetDenominator);
        self::assertSame('80', SimpleMatureReturnBaseline::delivered(80, 60, '20', '100')->expectedNetNumerator);
    }

    public function testOpenBaselinePreservesExactGrossAndMatureRate(): void
    {
        $q = SimpleMatureReturnBaseline::open(15, '2', '3', '1', '5');
        self::assertSame('8', $q->expectedNetNumerator);
        self::assertSame('1', $q->expectedNetDenominator);
    }
}
