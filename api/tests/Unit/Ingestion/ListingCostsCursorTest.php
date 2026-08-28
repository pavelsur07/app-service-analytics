<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Infrastructure\Query\ListingCostsCursor;
use PHPUnit\Framework\TestCase;

final class ListingCostsCursorTest extends TestCase
{
    public function testConditionMatchesTheSortDirection(): void
    {
        $condition = (new ListingCostsCursor(1000, 'abc'))->after('revenue_minor');

        // Сортировка — «выручка по убыванию, артикул по возрастанию».
        // Значит при равной выручке следующая страница начинается
        // с бо́льшего артикула. Кортежное сравнение здесь давало меньший:
        // строки пропадали и дублировались, и задевало это самый частый
        // случай — у карточек без продаж выручка нулевая у всех.
        self::assertStringContainsString('revenue_minor < :cursorAmount', $condition);
        self::assertStringContainsString('marketplace_sku > :cursorSku', $condition);
    }

    public function testRoundTrip(): void
    {
        $cursor = ListingCostsCursor::fromString('1000:abc');

        self::assertNotNull($cursor);
        self::assertSame(1000, $cursor->revenueMinor);
        self::assertSame('abc', $cursor->marketplaceSku);
        self::assertSame('1000:abc', $cursor->toString());
    }

    public function testMalformedCursorIsRejected(): void
    {
        self::assertNull(ListingCostsCursor::fromString('abc'));
        self::assertNull(ListingCostsCursor::fromString('nope:abc'));
    }
}
