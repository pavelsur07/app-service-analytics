<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Message;

/**
 * Полная синхронизация каталога одного подключения. Периода здесь нет
 * и быть не может: каталог — снимок «что у продавца есть сейчас»,
 * а не события за день (в отличие от FetchOzonPostingsMessage).
 */
final readonly class FetchOzonCatalogMessage
{
    public function __construct(
        public string $companyId,
        public string $marketplaceAccountId,
    ) {
    }
}
