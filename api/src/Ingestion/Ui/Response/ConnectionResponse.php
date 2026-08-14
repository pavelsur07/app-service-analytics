<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response;

/**
 * Подключение на экране.
 *
 * $state — active | broken | revoked (ADR-007). Клиент обязан различать
 * их сам: broken означает «синхронизация остановлена, нужно переподключить»,
 * и метка об этом — вторая половина письма, без которой уведомление
 * превращается в тупик.
 *
 * $lastLoadedAt — тип выгрузки => момент последней загрузки, ISO-8601.
 * Пусто, если по подключению ещё ничего не приходило.
 */
final readonly class ConnectionResponse
{
    /**
     * @param array<string, string> $lastLoadedAt
     */
    public function __construct(
        public string $id,
        public string $marketplace,
        public string $externalShopId,
        public string $state,
        public string $createdAt,
        public array $lastLoadedAt,
        /** Версия для оптимистической блокировки (ADR-008): клиент
         * присылает её обратно при замене ключей. */
        public int $version,
    ) {
    }
}
