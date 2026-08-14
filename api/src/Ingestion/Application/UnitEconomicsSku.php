<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

/**
 * Экономика одного артикула за период.
 *
 * $marginMinor — выручка минус комиссия минус расходы. Комиссия
 * и расходы приходят от площадки отрицательными, поэтому здесь сложение,
 * а не вычитание: знак — часть данных, и «взять по модулю» означало бы
 * сложить расход с выручкой.
 *
 * Себестоимости в расчёте нет: её ввод — отдельный модуль (ADR-008,
 * ADR-011). Пока это «сколько осталось от продажи после расходов
 * площадки», и на экране это названо именно так.
 */
final readonly class UnitEconomicsSku
{
    /**
     * @param list<UnitEconomicsExpense> $expenses
     */
    public function __construct(
        public string $marketplaceSku,
        public int $deliveredQuantity,
        public int $orderedQuantity,
        public int $revenueMinor,
        public int $commissionMinor,
        public array $expenses,
        public int $expensesTotalMinor,
        /**
         * Комиссия плюс расходы — то, что съедает выручку. Считается
         * здесь, а не в компоненте: арифметика над денежными величинами
         * в компонентах запрещена (CLAUDE.md §10).
         */
        public int $deductionsTotalMinor,
        public int $marginMinor,
    ) {
    }
}
