<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Infrastructure\Query\BuyoutRateCursor;
use App\Ingestion\Infrastructure\Query\BuyoutRateDirection;
use App\Ingestion\Infrastructure\Query\BuyoutRateSort;
use PHPUnit\Framework\TestCase;

final class BuyoutRateCursorTest extends TestCase
{
    public function testRoundTripKeepsViewAndSkuColons(): void
    {
        $cursor = BuyoutRateCursor::fromString('actual_buyout:asc:90:4546:sku:variant');

        self::assertNotNull($cursor);
        self::assertSame(BuyoutRateSort::ActualBuyout, $cursor->sort);
        self::assertSame(BuyoutRateDirection::Asc, $cursor->direction);
        self::assertSame(90, $cursor->days);
        self::assertSame(4546, $cursor->sortValue);
        self::assertSame('sku:variant', $cursor->marketplaceSku);
        self::assertSame('actual_buyout:asc:90:4546:sku:variant', $cursor->toString());
    }

    public function testNullActualRateContinuesInsideNullGroupBySku(): void
    {
        $cursor = BuyoutRateCursor::fromString('actual_buyout:desc:30:~:SKU-B');

        self::assertNotNull($cursor);
        self::assertSame(
            '(rate.actual_buyout_rate_bps IS NULL AND rate.marketplace_sku > :cursorSku)',
            $cursor->after(),
        );
    }

    public function testNonNullRateContinuesToNullsAfterNumericRows(): void
    {
        $cursor = BuyoutRateCursor::fromString('actual_buyout:desc:30:7000:SKU-A');

        self::assertNotNull($cursor);
        self::assertSame(
            '(rate.actual_buyout_rate_bps IS NULL OR rate.actual_buyout_rate_bps < :cursorValue OR (rate.actual_buyout_rate_bps = :cursorValue AND rate.marketplace_sku > :cursorSku))',
            $cursor->after(),
        );
    }

    public function testRejectsValuesImpossibleForTheSelectedMetric(): void
    {
        self::assertNull(BuyoutRateCursor::fromString('ordered:desc:30:~:SKU-A'));
        self::assertNull(BuyoutRateCursor::fromString('actual_buyout:desc:30:10001:SKU-A'));
    }

    public function testRejectsMalformedCursor(): void
    {
        self::assertNull(BuyoutRateCursor::fromString('broken'));
        self::assertNull(BuyoutRateCursor::fromString('ordered:sideways:30:10:SKU-A'));
        self::assertNull(BuyoutRateCursor::fromString('ordered:desc:x:10:SKU-A'));
        self::assertNull(BuyoutRateCursor::fromString('ordered:desc:30:-1:SKU-A'));
        self::assertNull(BuyoutRateCursor::fromString('ordered:desc:30:10:'));
    }

    public function testMatchesOnlyTheViewThatIssuedIt(): void
    {
        $cursor = BuyoutRateCursor::fromString('ordered:desc:30:10:SKU-A');

        self::assertNotNull($cursor);
        self::assertTrue($cursor->matches(BuyoutRateSort::Ordered, BuyoutRateDirection::Desc, 30));
        self::assertFalse($cursor->matches(BuyoutRateSort::ActualBuyout, BuyoutRateDirection::Desc, 30));
        self::assertFalse($cursor->matches(BuyoutRateSort::Ordered, BuyoutRateDirection::Asc, 30));
        self::assertFalse($cursor->matches(BuyoutRateSort::Ordered, BuyoutRateDirection::Desc, 90));
    }
}
