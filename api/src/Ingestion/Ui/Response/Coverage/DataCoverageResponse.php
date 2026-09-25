<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\Coverage;

final readonly class DataCoverageResponse
{
    /**
     * @param list<string>                  $days дни месяца, Y-m-d
     * @param list<DataCoverageRowResponse> $rows строки эндпоинтов
     */
    public function __construct(
        /** месяц отчёта, Y-m */
        public string $month,
        /** сегодняшний день по Москве, Y-m-d */
        public string $today,
        public array $days,
        public DataCoverageRowResponse $total,
        public array $rows,
    ) {
    }
}
