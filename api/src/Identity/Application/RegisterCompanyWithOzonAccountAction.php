<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\MarketplaceCredentialsEncryptor;
use App\Identity\Domain\ValueObject\Marketplace;
use App\Identity\Domain\ValueObject\MarketplaceCredentials;

/**
 * Саморегистрация отложена (ADR-007) — учётки первым клиентам заводятся
 * этим сценарием через консольную команду, не через UI.
 */
final readonly class RegisterCompanyWithOzonAccountAction
{
    public function __construct(
        private CompanyRepository $companies,
        private MarketplaceAccountRepository $marketplaceAccounts,
        private MarketplaceCredentialsEncryptor $credentialsEncryptor,
    ) {
    }

    /**
     * @param array<string, string> $credentials
     */
    public function __invoke(string $companyName, string $externalShopId, array $credentials): MarketplaceAccount
    {
        $company = Company::register($companyName);
        $this->companies->add($company);

        $encrypted = $this->credentialsEncryptor->encrypt(MarketplaceCredentials::fromArray($credentials));

        $account = MarketplaceAccount::connect(
            companyId: $company->id(),
            marketplace: Marketplace::Ozon,
            externalShopId: $externalShopId,
            credentialsCiphertext: $encrypted->ciphertext,
            credentialsKeyVersion: $encrypted->keyVersion,
        );
        $this->marketplaceAccounts->add($account);

        return $account;
    }
}
