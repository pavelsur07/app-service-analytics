<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Infrastructure\Query\UnitEconomicsSkuRow;
use App\Ingestion\Infrastructure\Query\UnitEconomicsSort;
use PHPUnit\Framework\TestCase;

final class UnitEconomicsSortTest extends TestCase
{
    /**
     * column() называет колонку, по которой строится ORDER BY,
     * а valueOf() достаёт из строки значение для курсора. Разъедься
     * они — курсор указывал бы на позицию в другом порядке, и страницы
     * начали бы пропускать и повторять строки молча.
     *
     * Проверка идёт по cases(), а не по списку в тесте: новый вариант
     * сортировки обязан попасть в обе карты, и забыть одну из них
     * этот цикл не даст.
     */
    public function testColumnAndValueDescribeTheSameField(): void
    {
        $row = new UnitEconomicsSkuRow(
            marketplaceSku: 'sku-1',
            currency: 'RUB',
            deliveredQuantity: 11,
            deliveredAmountMinor: 22,
            commissionAmountMinor: -33,
            orderedQuantity: 44,
            expensesTotalMinor: -55,
            costTotalMinor: -66,
            quantityWithoutCost: 77,
            costCorrectedAt: null,
            marginMinor: 88,
            name: null,
            offerId: null,
        );

        $byColumn = [
            'delivered_quantity' => 11,
            'delivered_amount_minor' => 22,
            'commission_amount_minor' => -33,
            'expenses_total_minor' => -55,
            'cost_total_minor' => -66,
            'margin_minor' => 88,
        ];

        foreach (UnitEconomicsSort::cases() as $sort) {
            self::assertArrayHasKey($sort->column(), $byColumn, $sort->value);
            self::assertSame($byColumn[$sort->column()], $sort->valueOf($row), $sort->value);
        }
    }
}
