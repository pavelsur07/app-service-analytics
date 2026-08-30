<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Infrastructure\Query;

use App\Ingestion\Infrastructure\Query\BuyoutRateQuery;
use PHPUnit\Framework\TestCase;

final class BuyoutRateQueryTest extends TestCase
{
    public function testMapsRatesWithZeroDenominatorAsUnknown(): void
    {
        $row = BuyoutRateQuery::mapRow([
            'marketplace_sku' => 'SKU-ZERO',
            'offer_id' => null,
            'name' => null,
            'ordered_quantity' => '0',
            't1_quantity' => '0',
            'delivered_quantity' => '0',
            't2_quantity' => '0',
            'partial_return_quantity' => '0',
            'client_return_quantity' => '0',
            'unresolved_quantity' => '0',
            'conversion_rate_bps' => null,
            'actual_buyout_rate_bps' => null,
            'resolution_rate_bps' => null,
            't1_rate_bps' => null,
            't2_rate_bps' => null,
            'partial_return_rate_bps' => null,
            'maturity_status' => 'preliminary',
        ]);

        self::assertNull($row->resolutionRateBps);
        self::assertNull($row->t1RateBps);
        self::assertNull($row->t2RateBps);
        self::assertNull($row->partialReturnRateBps);
    }
}
