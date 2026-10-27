<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\Dashboard;

use App\Ingestion\Application\Dashboard\DashboardProfitDetails;
use OpenApi\Attributes as OA;

/** Начисления и известная часть себестоимости со знаками; при dataComplete=false компоненты частичные. */
final readonly class DashboardProfitDetailsResponse
{
    public function __construct(
        /** Есть строки начислений, в том числе с взаимно погашенным итогом. */
        public bool $hasAccruals,
        public int $accrualsTotalMinor,
        /** Со знаком: продажа отрицательна, восстановление при возврате положительно. */
        public int $costTotalMinor,
        /** Сумма quantity продаж и возвратов без цены; не погашается между собой. */
        public int $quantityWithoutCost,
        public string $costMethod,
        #[OA\Property(format: 'date-time', nullable: true)]
        public ?string $costCorrectedAt,
    ) {
    }

    public static function from(DashboardProfitDetails $details): self
    {
        return new self($details->hasAccruals, $details->accrualsTotalMinor, $details->costTotalMinor,
            $details->quantityWithoutCost, $details->costMethod, $details->costCorrectedAt);
    }
}
