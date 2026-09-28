<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\UnitEconomics;

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
        /** Расходы кабинета вместе с остатком рекламы. */
        public int $cabinetExpensesTotalMinor,
        /**
         * Дни окна (кроме сегодняшнего), где реклама по товарам
         * не сошлась с финансовым отчётом площадки. Ноль — сошлась.
         */
        public int $advertisingUnreconciledDays,
        /**
         * Дни окна, за которые продажи загружены, а расходы нет:
         * маржа за них завышена. Ноль — отчёт полон.
         */
        public int $daysWithoutExpenses,
        public ?string $nextCursor,
    ) {
    }
}
