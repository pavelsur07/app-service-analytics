<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Infrastructure\Query;

use App\Ingestion\Infrastructure\Query\Buyout\BuyoutDailyQuery;
use PHPUnit\Framework\TestCase;

final class BuyoutDailyQueryTest extends TestCase
{
    public function testMapsResolutionRateWithZeroDenominatorAsUnknown(): void
    {
        $row = BuyoutDailyQuery::mapRow([
            'business_date' => '2026-08-30',
            'actual_buyout_rate_bps' => null,
            'projected_buyout_rate_bps' => null,
            'resolution_rate_bps' => null,
            'ordered_quantity' => '0',
            'resolved_quantity' => '0',
            'projected_buyout_quantity' => null,
            'maturity_status' => 'preliminary',
            'in_flight_rate_bps' => null,
        ]);

        self::assertNull($row->resolutionRateBps);
        self::assertNull($row->inFlightRateBps);
    }
}
