<?php

declare(strict_types=1);

namespace App\Identity\Application\Facade;

/**
 * Только идентификаторы — в отличие от OzonSyncTarget, не несёт
 * расшифрованные credentials: планировщику они не нужны, расшифровка
 * происходит позже, внутри FetchOzonPostingsHandler, только для
 * аккаунта, который реально обрабатывается.
 */
final readonly class OzonAccountRef
{
    public function __construct(
        public string $companyId,
        public string $marketplaceAccountId,
    ) {
    }
}
