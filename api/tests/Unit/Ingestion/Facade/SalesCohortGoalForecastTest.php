<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Facade;

use App\Ingestion\Application\Facade\SalesCohortGoalForecast;
use PHPUnit\Framework\TestCase;

final class SalesCohortGoalForecastTest extends TestCase
{
    public function testCanonicalPairsAreCompleteOrHaveAnExplicitIndependentReason(): void
    {
        $value = new SalesCohortGoalForecast('1', '3', null, null, null, 'gross_probability_unavailable');
        self::assertSame('1', $value->retainedNumerator);
        self::assertNull($value->futureT1Numerator);
        foreach ([[null, '1', null], ['1', null, null], ['1', '0', null], ['-1', '1', null], ['01', '1', null], ['1', '1', 'wrong'], [null, null, null], [str_repeat('1', 16385), '1', null]] as [$n, $d, $reason]) {
            try {
                new SalesCohortGoalForecast($n, $d, $reason, '0', '1', null);
                self::fail('Invalid exact pair accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
