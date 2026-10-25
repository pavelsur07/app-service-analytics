<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\Admin;

final readonly class ServiceCoverageRowResponse
{
    public function __construct(
        public string $resourceType,
        /** Y-m-d — день, Y-m — месяц, ref — справочник */
        public string $periodKey,
        /** кабинеты, у которых ресурс ожидается; null — строки у сервиса нет */
        public ?int $cabinetsExpected,
        public int $done,
        public int $running,
        public int $pending,
        public int $notReady,
        public int $failedRetryable,
        public int $failedPermanent,
        /** последняя успешная загрузка, ATOM UTC */
        public ?string $lastSuccessAt,
        /** кабинеты allowlist с загруженным PHP днём; null — у ресурса нет пары в PHP или сравнение выключено */
        public ?int $phpCabinets,
    ) {
    }
}
