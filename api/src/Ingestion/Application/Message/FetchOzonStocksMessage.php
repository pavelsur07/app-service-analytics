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
        /**
         * Первый снимок после подключения: каталог может ещё грузиться,
         * и пустой каталог — повод для повтора, а не для тихого выхода
         * (иначе первый день снимка потерян, а задним числом его не взять).
         */
        public bool $retryIfCatalogEmpty = false,
        /** Номер попытки дождаться каталога (FetchOzonStocksHandler::MAX_CATALOG_WAITS). */
        public int $attempt = 1,
    ) {
    }
}
