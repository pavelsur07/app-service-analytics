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
        /** Курсор следующей страницы; null — страница последняя. */
        public ?string $nextCursor,
    ) {
    }
}
