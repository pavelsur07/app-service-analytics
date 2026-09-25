<?php

declare(strict_types=1);

namespace App\Identity\Application\Facade;

/**
 * Цель рекламной синхронизации (ADR-026 п. 1) — плоские скаляры, как
 * у `OzonSyncTarget`. Отдельный класс, а не два поля в `OzonSyncTarget`:
 * обработчики продаж и расходов рекламный секрет не получают вовсе.
 */
final readonly class OzonAdvertisingTarget
{
    public function __construct(
        public string $companyId,
        public string $marketplaceAccountId,
        public string $performanceClientId,
        public string $performanceClientSecret,
    ) {
    }
}
