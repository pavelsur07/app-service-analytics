<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response;

/**
 * Экономика одного артикула за период.
 *
 * marginMinor — «сколько осталось от продажи после расходов площадки»,
 * а не прибыль: себестоимости в расчёте нет, её ввод — отдельный модуль.
 * Экран называет это именно так, и контракт не притворяется иначе.
 */
final readonly class UnitEconomicsSkuResponse
{
    /**
     * @param list<UnitEconomicsExpenseResponse> $expenses
     */
    public function __construct(
        public string $marketplaceSku,
        public int $deliveredQuantity,
        public int $orderedQuantity,
        public int $revenueMinor,
        public int $commissionMinor,
        public array $expenses,
        public int $expensesTotalMinor,
        public int $marginMinor,
    ) {
    }
}
