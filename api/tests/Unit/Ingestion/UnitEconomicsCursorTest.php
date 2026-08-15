<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Infrastructure\Query\UnitEconomicsCursor;
use PHPUnit\Framework\TestCase;

final class UnitEconomicsCursorTest extends TestCase
{
    public function testConditionMatchesTheSortDirection(): void
    {
        $condition = (new UnitEconomicsCursor(1000, 'abc'))->after('revenue_minor');

        // Сортировка — «сумма по убыванию, артикул по возрастанию».
        // Значит при равной сумме следующая страница начинается
        // с бо́льшего артикула. Кортежное сравнение здесь давало меньший:
        // строки пропадали и дублировались, и задевало это самый частый
        // случай — у карточек без продаж выручка нулевая у всех.
        self::assertStringContainsString('revenue_minor < :cursorAmount', $condition);
        self::assertStringContainsString('marketplace_sku > :cursorSku', $condition);
    }

    public function testRoundTrip(): void
    {
        $cursor = UnitEconomicsCursor::fromString('1000:abc');

        self::assertNotNull($cursor);
        self::assertSame(1000, $cursor->deliveredAmountMinor);
        self::assertSame('abc', $cursor->marketplaceSku);
        self::assertSame('1000:abc', $cursor->toString());
    }

    public function testMalformedCursorIsRejected(): void
    {
        self::assertNull(UnitEconomicsCursor::fromString('abc'));
        self::assertNull(UnitEconomicsCursor::fromString('nope:abc'));
    }
}
