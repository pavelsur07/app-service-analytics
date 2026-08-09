<?php

declare(strict_types=1);

namespace App\Identity\Application\Facade;

/**
 * Публичный контракт IdentityFacade — плоские скаляры, не Entity/VO
 * Identity\Domain: Ingestion не должен видеть внутреннее устройство
 * MarketplaceAccount, только то, что нужно для синхронизации.
 */
final readonly class OzonSyncTarget
{
    public function __construct(
        public string $companyId,
        public string $marketplaceAccountId,
        public string $externalShopId,
        public string $clientId,
        public string $apiKey,
    ) {
    }
}
