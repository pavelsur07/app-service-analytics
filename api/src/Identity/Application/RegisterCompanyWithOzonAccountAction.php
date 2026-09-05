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
 * Продуктовый путь заведения кабинета — HTTP-онбординг (ADR-021),
 * не этот сценарий: саморегистрация и подключение кабинета через UI
 * работают в проде. Сегодняшний единственный потребитель —
 * сидирование локальной песочницы и сквозной сценарий `bin/e2e-seed.sh`
 * (запускается консольной командой `app:identity:seed-ozon-sandbox-company`).
 *
 * Аудит-запись о подключении не пишется намеренно, а не по недосмотру:
 * у обеих фабрик `AuditRecord` актор обязателен и не nullable, а у
 * оператора, запускающего команду в терминале, нет ни пользователя,
 * ни сессии, которые сюда подставить. Изобретать системного актора
 * ради одного сидирующего сценария отвергнуто тем же аргументом, каким
 * ADR-011 отверг журнал заранее — подсистема до появления потребителя.
 * Подробности и условие пересмотра — ADR-023.
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
    public function __invoke(string $companyName, string $name, string $externalShopId, array $credentials): MarketplaceAccount
    {
        $company = Company::register($companyName);
        $this->companies->add($company);

        $encrypted = $this->credentialsEncryptor->encrypt(MarketplaceCredentials::fromArray($credentials));

        $account = MarketplaceAccount::connect(
            companyId: $company->id(),
            marketplace: Marketplace::Ozon,
            name: $name,
            externalShopId: $externalShopId,
            credentialsCiphertext: $encrypted->ciphertext,
            credentialsKeyVersion: $encrypted->keyVersion,
        );
        $this->marketplaceAccounts->add($account);

        return $account;
    }
}
