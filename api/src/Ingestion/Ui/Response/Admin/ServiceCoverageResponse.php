<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\Admin;

use OpenApi\Attributes as OA;

/**
 * Экран «Покрытие GO»: сводка ozon-fetcher рядом с загрузкой PHP.
 * Только счётчики — ни компаний, ни кабинетов, ни сумм.
 */
final readonly class ServiceCoverageResponse
{
    /**
     * @param list<ServiceCoverageRowResponse> $rows ресурс × период, по ресурсу и периоду
     */
    public function __construct(
        /** первый день окна, Y-m-d по Москве */
        public string $from,
        /** последний день окна (сегодня), Y-m-d по Москве */
        public string $to,
        /** время запроса, ATOM UTC */
        public string $checkedAt,
        #[OA\Property(enum: ['ok', 'disabled', 'not_ready', 'unavailable'])]
        public string $serviceState,
        /** время сводки сервиса, ATOM UTC; есть только у ok */
        public ?string $serviceGeneratedAt,
        /** кабинеты сервиса с ключом Seller; есть только у ok */
        public ?int $cabinetsSeller,
        /** кабинеты сервиса с ключом Performance (по рекламным строкам); есть только у ok */
        public ?int $cabinetsPerformance,
        /** задан ли allowlist сервиса в api: без него загрузку PHP сравнивать не с чем */
        public bool $phpCompared,
        public array $rows,
    ) {
    }
}
