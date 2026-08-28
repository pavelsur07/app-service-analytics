<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Infrastructure\Query\UnitEconomicsCursor;
use App\Ingestion\Infrastructure\Query\UnitEconomicsDirection;
use App\Ingestion\Infrastructure\Query\UnitEconomicsSort;
use PHPUnit\Framework\TestCase;

final class UnitEconomicsCursorTest extends TestCase
{
    public function testConditionMatchesTheSortDirection(): void
    {
        $condition = (new UnitEconomicsCursor(
            UnitEconomicsSort::Revenue,
            UnitEconomicsDirection::Desc,
            1000,
            'abc',
        ))->after();

        // Сортировка — «сумма по убыванию, артикул по возрастанию».
        // Значит при равной сумме следующая страница начинается
        // с бо́льшего артикула. Кортежное сравнение здесь давало меньший:
        // строки пропадали и дублировались, и задевало это самый частый
        // случай — у карточек без продаж выручка нулевая у всех.
        self::assertStringContainsString('delivered_amount_minor < :cursorValue', $condition);
        self::assertStringContainsString('marketplace_sku > :cursorSku', $condition);
    }

    /**
     * Направление переворачивает сравнение по колонке сортировки —
     * и только его.
     *
     * Тай-брейк по артикулу остаётся `>` при любом направлении, потому
     * что вторым столбцом артикул всегда идёт по возрастанию. Симметрично
     * перевернуть и его выглядит логично и ломает пагинацию ровно тем же
     * способом, от которого написан тест выше: при равных значениях
     * строки начнут пропадать и повторяться. Этот тест — единственное,
     * что стоит между «выглядит стройнее» и молчаливой потерей строк.
     */
    public function testAscendingFlipsOnlyTheSortColumn(): void
    {
        $condition = (new UnitEconomicsCursor(
            UnitEconomicsSort::Margin,
            UnitEconomicsDirection::Asc,
            -500,
            'abc',
        ))->after();

        self::assertStringContainsString('margin_minor > :cursorValue', $condition);
        self::assertStringContainsString('marketplace_sku > :cursorSku', $condition);
    }

    public function testRoundTrip(): void
    {
        $cursor = UnitEconomicsCursor::fromString('margin:asc:-500:abc');

        self::assertNotNull($cursor);
        self::assertSame(UnitEconomicsSort::Margin, $cursor->sort);
        self::assertSame(UnitEconomicsDirection::Asc, $cursor->direction);
        self::assertSame(-500, $cursor->sortValue);
        self::assertSame('abc', $cursor->marketplaceSku);
        self::assertSame('margin:asc:-500:abc', $cursor->toString());
    }

    /**
     * Артикул забирает весь остаток строки: двоеточие внутри него
     * не должно разваливать разбор.
     */
    public function testSkuKeepsItsColons(): void
    {
        $cursor = UnitEconomicsCursor::fromString('revenue:desc:10:a:b:c');

        self::assertNotNull($cursor);
        self::assertSame('a:b:c', $cursor->marketplaceSku);
    }

    public function testMalformedCursorIsRejected(): void
    {
        self::assertNull(UnitEconomicsCursor::fromString('abc'));
        self::assertNull(UnitEconomicsCursor::fromString('nope:abc'));
        // Старая форма «сумма:артикул» — курсоров без порядка больше нет.
        self::assertNull(UnitEconomicsCursor::fromString('1000:abc'));
        self::assertNull(UnitEconomicsCursor::fromString('bogus:desc:10:abc'));
        self::assertNull(UnitEconomicsCursor::fromString('revenue:sideways:10:abc'));
        self::assertNull(UnitEconomicsCursor::fromString('revenue:desc:notanumber:abc'));
        self::assertNull(UnitEconomicsCursor::fromString('revenue:desc:10:'));
    }

    /**
     * Курсор, снятый при одной сортировке, на другой указывает
     * на другое место. Страница вышла бы правдоподобной и неверной,
     * поэтому несовпадение обязано быть различимым.
     */
    public function testCursorKnowsWhichOrderItWasIssuedFor(): void
    {
        $cursor = new UnitEconomicsCursor(
            UnitEconomicsSort::Revenue,
            UnitEconomicsDirection::Desc,
            1000,
            'abc',
        );

        self::assertTrue($cursor->matches(UnitEconomicsSort::Revenue, UnitEconomicsDirection::Desc));
        self::assertFalse($cursor->matches(UnitEconomicsSort::Margin, UnitEconomicsDirection::Desc));
        self::assertFalse($cursor->matches(UnitEconomicsSort::Revenue, UnitEconomicsDirection::Asc));
    }
}
