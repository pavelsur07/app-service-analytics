<?php

declare(strict_types=1);

namespace App\Identity\Application\Facade;

use App\Identity\Domain\MarketplaceAccountRepository;
use Symfony\Component\Uid\Uuid;

/** Company-scoped account check without marketplace credentials. */
final readonly class IdentityAccountScopeFacade
{
    public function __construct(private MarketplaceAccountRepository $accounts)
    {
    }

    public function ownsMarketplaceAccount(string $companyId, string $marketplaceAccountId): bool
    {
        return null !== $this->accounts->get($companyId, Uuid::fromString($marketplaceAccountId));
    }
}
