<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

/**
 * Юнит-экономика за период: по товарам и отдельно по кабинету.
 *
 * Расходы кабинета не размазаны по товарам намеренно (ADR-012): базис
 * распределения — продуктовое решение, которое захочется менять,
 * а показанная строка честнее доли, происхождение которой клиент
 * не проверит.
 */
final readonly class UnitEconomicsReport
{
    /**
     * @param list<UnitEconomicsSku>     $skus
     * @param list<UnitEconomicsExpense> $cabinetExpenses
     */
    public function __construct(
        public array $skus,
        public array $cabinetExpenses,
        public int $cabinetExpensesTotalMinor,
        public string $currency,
        /**
         * Сколько дней окна показывают маржу, посчитанную без расходов:
         * продажи за них загружены, расходы нет. Ноль — отчёт полон.
         *
         * Число, а не признак «да/нет»: клиенту важно, четыре это дня
         * из тридцати или двадцать восемь. В первом случае цифру можно
         * читать, во втором — нельзя.
         */
        public int $daysWithoutExpenses,
        /** Курсор следующей страницы; null — страница последняя. */
        public ?string $nextCursor,
    ) {
    }
}
