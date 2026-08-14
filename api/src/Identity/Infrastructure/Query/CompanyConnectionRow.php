<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Query;

/**
 * Строка результата CompanyConnectionsQuery (CLAUDE.md §5).
 * Учётных данных здесь нет и быть не может — они не выбираются запросом.
 */
final readonly class CompanyConnectionRow
{
    public function __construct(
        public string $id,
        public string $marketplace,
        public string $externalShopId,
        public string $state,
        public string $createdAt,
        /** Версия для оптимистической блокировки (ADR-008): клиент обязан
         * прислать её обратно при изменении. */
        public int $version,
    ) {
    }
}
