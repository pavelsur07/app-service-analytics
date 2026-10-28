<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingRequest;
use PHPUnit\Framework\TestCase;

final class NetTrainingRequestTest extends TestCase
{
    public function testExactRequestedSkusStayDistinctAndBounded(): void
    {
        self::assertSame(['100', '200'], (new NetTrainingRequest(['100', '200']))->marketplaceSkus);
        foreach ([[], ['100', '100'], [''], ["bad\nSKU"], [str_repeat('x', 65)], array_map(strval(...), range(1, 51))] as $invalid) {
            try {
                new NetTrainingRequest($invalid);
                self::fail('Invalid SKU scope must not start training.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testUntrustedSkuTypesAreRejectedBeforeStringOperations(): void
    {
        foreach ([[123], [null], [['nested']], ['valid', false]] as $invalid) {
            try {
                new NetTrainingRequest($invalid);
                self::fail('Non-string SKU must be rejected at the input boundary.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
