<?php

declare(strict_types=1);

namespace App\SalesPlanning\Infrastructure\Query;

/** Неизменная числовая база: первая успешно утверждённая версия линии. */
final readonly class SalesPlanBaselineRow
{
    public function __construct(
        public string $id,
        public string $lineageId,
        public string $marketplaceAccountId,
        public int $versionNumber,
        public ?string $metric,
        public string $currency,
        public string $dateFrom,
        public string $dateTo,
    ) {
    }
}
