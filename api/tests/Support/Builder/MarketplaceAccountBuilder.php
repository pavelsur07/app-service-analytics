<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\ValueObject\Marketplace;

/**
 * ADR-005: валидные умолчания, неизменяем, связанную Company создаёт
 * через её билдер, если не задана явно через withCompany().
 */
final class MarketplaceAccountBuilder
{
    private ?Company $company = null;
    private Marketplace $marketplace = Marketplace::Ozon;
    private string $externalShopId = 'sandbox-shop';
    private string $credentialsCiphertext = 'stub-ciphertext';
    private int $credentialsKeyVersion = 1;

    private function __construct()
    {
    }

    public static function aMarketplaceAccount(): self
    {
        return new self();
    }

    public function withCompany(Company $company): self
    {
        $clone = clone $this;
        $clone->company = $company;

        return $clone;
    }

    public function withExternalShopId(string $externalShopId): self
    {
        $clone = clone $this;
        $clone->externalShopId = $externalShopId;

        return $clone;
    }

    public function withCredentials(string $ciphertext, int $keyVersion): self
    {
        $clone = clone $this;
        $clone->credentialsCiphertext = $ciphertext;
        $clone->credentialsKeyVersion = $keyVersion;

        return $clone;
    }

    public function build(): MarketplaceAccount
    {
        $company = $this->company ?? CompanyBuilder::aCompany()->build();

        return MarketplaceAccount::connect(
            companyId: $company->id(),
            marketplace: $this->marketplace,
            externalShopId: $this->externalShopId,
            credentialsCiphertext: $this->credentialsCiphertext,
            credentialsKeyVersion: $this->credentialsKeyVersion,
        );
    }

    public function persistWith(CompanyRepository $companies, MarketplaceAccountRepository $marketplaceAccounts): MarketplaceAccount
    {
        $company = $this->company ?? CompanyBuilder::aCompany()->persistWith($companies);

        $account = MarketplaceAccount::connect(
            companyId: $company->id(),
            marketplace: $this->marketplace,
            externalShopId: $this->externalShopId,
            credentialsCiphertext: $this->credentialsCiphertext,
            credentialsKeyVersion: $this->credentialsKeyVersion,
        );
        $marketplaceAccounts->add($account);

        return $account;
    }
}
