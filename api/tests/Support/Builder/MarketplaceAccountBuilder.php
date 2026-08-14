<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\MarketplaceCredentialsEncryptor;
use App\Identity\Domain\ValueObject\Marketplace;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Identity\Domain\ValueObject\MarketplaceCredentials;

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
    private MarketplaceAccountState $state = MarketplaceAccountState::Active;

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

    /**
     * Шифрует по-настоящему через переданный шифр (обычно взятый
     * из контейнера в интеграционном тесте) — в отличие от withCredentials(),
     * результат реально расшифровывается тем же CredentialsCipher, каким
     * его будет читать проверяемый код. withCredentials() с непрозрачной
     * строкой достаточно там, где содержимое ciphertext не проверяется.
     *
     * @param array<string, string> $credentials
     */
    public function withPlaintextCredentials(array $credentials, MarketplaceCredentialsEncryptor $encryptor): self
    {
        $encrypted = $encryptor->encrypt(MarketplaceCredentials::fromArray($credentials));

        return $this->withCredentials($encrypted->ciphertext, $encrypted->keyVersion);
    }

    public function withState(MarketplaceAccountState $state): self
    {
        $clone = clone $this;
        $clone->state = $state;

        return $clone;
    }

    public function build(): MarketplaceAccount
    {
        $company = $this->company ?? CompanyBuilder::aCompany()->build();
        $account = MarketplaceAccount::connect(
            companyId: $company->id(),
            marketplace: $this->marketplace,
            externalShopId: $this->externalShopId,
            credentialsCiphertext: $this->credentialsCiphertext,
            credentialsKeyVersion: $this->credentialsKeyVersion,
        );
        $this->applyState($account);

        return $account;
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
        $this->applyState($account);
        $marketplaceAccounts->add($account);

        return $account;
    }

    /**
     * До вставки, а не условным UPDATE после неё: сырой UPDATE разошёлся бы
     * с ORM-кэшем в том же процессе, и тест проверял бы устаревшую сущность
     * вместо строки в базе. Боевой переход в broken идёт другим путём —
     * MarketplaceAccountRepository::markBrokenIfActive (ADR-007).
     */
    private function applyState(MarketplaceAccount $account): void
    {
        match ($this->state) {
            MarketplaceAccountState::Active => null,
            MarketplaceAccountState::Broken => $account->markBroken(),
            MarketplaceAccountState::Revoked => $account->revoke(),
        };
    }
}
