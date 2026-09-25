<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\Coverage;

use OpenApi\Attributes as OA;

final readonly class DataCoverageRowResponse
{
    /**
     * @param list<string>      $statuses       по дню месяца
     * @param list<string|null> $lastReceivedAt по дню месяца, ATOM UTC
     */
    public function __construct(
        /** raw-тип; у строки «Итого» — `total` */
        public string $key,
        public string $section,
        /** метод и путь Ozon; у строки «Итого» — пусто */
        public string $endpoint,
        #[OA\Property(type: 'array', items: new OA\Items(type: 'string', enum: ['loaded', 'missing', 'failed', 'pending']))]
        public array $statuses,
        #[OA\Property(type: 'array', items: new OA\Items(type: 'string', format: 'date-time', nullable: true))]
        public array $lastReceivedAt,
        /** загружено дней из тех, что уже должны быть загружены */
        public int $covered,
        /** дней, которые уже должны быть загружены */
        public int $due,
    ) {
    }
}
