<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\DemandMatrix;

use OpenApi\Attributes as OA;

/** Правила расчёта — частью ответа (объяснимость расчёта, CLAUDE.md). */
#[OA\Schema(required: [
    'windowDays', 'middleDays', 'shortDays', 'windowEnd', 'minSales', 'excludedStatuses', 'regionBasis', 'periodBasis', 'rounding',
])]
final readonly class DemandMatrixDefinitionsResponse
{
    /**
     * @param list<string> $excludedStatuses
     */
    public function __construct(
        public int $windowDays,
        public int $middleDays,
        public int $shortDays,
        /** Последний день окон — вчера по Москве; сегодняшний ещё не закончился. */
        public string $windowEnd,
        /** Ниже этого числа заказов за windowDays тренд не считается. */
        public int $minSales,
        public array $excludedStatuses,
        #[OA\Property(enum: ['buyer_cluster'])]
        public string $regionBasis,
        #[OA\Property(enum: ['order_date_europe_moscow'])]
        public string $periodBasis,
        #[OA\Property(enum: ['half_away_from_zero'])]
        public string $rounding,
    ) {
    }
}
