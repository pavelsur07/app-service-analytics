<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Message;

/**
 * Синхронизация одного бизнес-дня одного подключения (ADR-006:
 * скользящее окно и расписание — за пределами tracer bullet, здесь —
 * ручной/одиночный запуск). businessDate — Y-m-d, часовой пояс Ozon
 * (Europe/Moscow, ADR-009) для since/to вычисляет обработчик.
 */
final readonly class FetchOzonPostingsMessage
{
    public function __construct(
        public string $companyId,
        public string $marketplaceAccountId,
        public string $businessDate,
    ) {
    }
}
