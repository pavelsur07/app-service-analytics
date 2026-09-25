<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Message;

/**
 * Кусок загрузки расхода и статистики кампаний: дни `[from, to]`
 * включительно, `Y-m-d` по Москве, не длиннее
 * `OzonAdvertisingWindows::CHUNK_DAYS` (ADR-026 п. 4: синхронные методы
 * проверены на 30 днях, длиннее — нет). Длину проверяет обработчик.
 */
final readonly class FetchOzonAdCampaignStatsMessage
{
    public function __construct(
        public string $companyId,
        public string $marketplaceAccountId,
        public string $from,
        public string $to,
    ) {
    }
}
