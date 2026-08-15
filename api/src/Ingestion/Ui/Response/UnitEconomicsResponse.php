<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response;

final readonly class UnitEconomicsResponse
{
    /**
     * @param list<UnitEconomicsSkuResponse>     $skus
     * @param list<UnitEconomicsExpenseResponse> $cabinetExpenses
     */
    public function __construct(
        public string $from,
        public string $to,
        public string $currency,
        public array $skus,
        public array $cabinetExpenses,
        public int $cabinetExpensesTotalMinor,
        /**
         * Дни окна, за которые продажи загружены, а расходы нет:
         * маржа за них завышена. Ноль — отчёт полон.
         */
        public int $daysWithoutExpenses,
        public ?string $nextCursor,
    ) {
    }
}
