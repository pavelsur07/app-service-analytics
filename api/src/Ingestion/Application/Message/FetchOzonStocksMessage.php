<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Message;

/**
 * Снимок остатков FBO одного подключения (ADR-034). Даты здесь нет:
 * остаток — текущее состояние, дату снимка задаёт начало прогона.
 */
final readonly class FetchOzonStocksMessage
{
    public function __construct(
        public string $companyId,
        public string $marketplaceAccountId,
    ) {
    }
}
