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
        /**
         * Название и артикул селлера из каталога. null, пока карточка
         * не подтянулась синхронизацией: артикул встречается в фактах
         * раньше, чем в каталоге, и терять из-за этого строку расчёта
         * нельзя.
         */
        public ?string $name,
        public ?string $offerId,
        public ?string $photoUrl,
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
        /** Себестоимость проданного, отрицательная — как и прочие вычеты. */
        public int $costTotalMinor,
        /** Сколько проданных штук пришлось на дни без заданной цены. */
        public int $quantityWithoutCost,
        /**
         * Прибыль: маржа минус себестоимость. null — цена задана
         * не на все проданные дни, и тогда числа нет. Ноль вместо
         * неизвестной себестоимости завысил бы прибыль ровно на её
         * величину, и выглядело бы это как настоящая цифра (ADR-013).
         */
        public ?int $profitMinor,
        /**
         * Дата последней правки себестоимости, применённой к этому
         * периоду, либо null. Прибыль пересчитывается при чтении
         * (ADR-013), поэтому отчёт за прошлый месяц может измениться
         * между двумя открытиями — и экран обязан это назвать,
         * а не менять цифру молча.
         */
        public ?string $costCorrectedAt,
    ) {
    }
}
