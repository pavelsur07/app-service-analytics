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
        public ?string $nextCursor,
    ) {
    }
}
