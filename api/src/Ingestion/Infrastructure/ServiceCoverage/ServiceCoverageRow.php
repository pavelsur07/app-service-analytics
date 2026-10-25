<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\ServiceCoverage;

/**
 * Строка сводки покрытия сервиса: ресурс × период, число кабинетов
 * по статусам задач загрузки (контракт service-coverage v1).
 */
final readonly class ServiceCoverageRow
{
    public function __construct(
        public string $resourceType,
        /** `YYYY-MM-DD`, `YYYY-MM` или `ref` */
        public string $periodKey,
        public int $cabinetsExpected,
        public int $done,
        public int $running,
        public int $pending,
        public int $notReady,
        public int $failedRetryable,
        public int $failedPermanent,
        public ?\DateTimeImmutable $lastSuccessAt,
    ) {
    }
}
