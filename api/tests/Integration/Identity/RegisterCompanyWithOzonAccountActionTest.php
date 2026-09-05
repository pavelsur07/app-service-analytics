<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\RegisterCompanyWithOzonAccountAction;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Identity\Infrastructure\Crypto\CredentialsCipher;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Доказывает связку конструктора Entity + шифрования + реального Postgres:
 * не мок репозитория (ADR-005 отвергает моки репозиториев — они проверяют
 * настройку мока, а не то, что запрос работает).
 */
final class RegisterCompanyWithOzonAccountActionTest extends KernelTestCase
{
    public function testCreatesCompanyAndOzonAccountWithRoundTrippableCredentials(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var RegisterCompanyWithOzonAccountAction $action */
        $action = $container->get(RegisterCompanyWithOzonAccountAction::class);
        /** @var CredentialsCipher $cipher */
        $cipher = $container->get(CredentialsCipher::class);

        $account = ($action)('Sandbox LLC', 'Sandbox Shop', 'shop-1', ['client_id' => 'shop-1', 'api_key' => 'k-1']);

        self::assertSame(MarketplaceAccountState::Active, $account->state());

        $decrypted = $cipher->decrypt($account->credentialsCiphertext(), $account->credentialsKeyVersion());
        self::assertSame(['client_id' => 'shop-1', 'api_key' => 'k-1'], $decrypted->toArray());
    }

    public function testDuplicateMarketplaceAccountForSameCompanyIsRejected(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var CompanyRepository $companies */
        $companies = $container->get(CompanyRepository::class);
        /** @var MarketplaceAccountRepository $marketplaceAccounts */
        $marketplaceAccounts = $container->get(MarketplaceAccountRepository::class);
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);

        $company = CompanyBuilder::aCompany()->persistWith($companies);
        MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($company)
            ->withExternalShopId('shop-dup')
            ->persistWith($companies, $marketplaceAccounts);

        $this->expectException(UniqueConstraintViolationException::class);

        // Тот же company_id + external_shop_id создать заново нельзя —
        // здесь важен сам факт, что уникальный индекс (company_id,
        // marketplace, external_shop_id) реален на уровне БД, а не только
        // в Doctrine-мэппинге.
        $entityManager->getConnection()->insert('marketplace_account', [
            'id' => (string) Uuid::v7(),
            'company_id' => (string) $company->id(),
            'marketplace' => 'ozon',
            'name' => 'Duplicate Shop',
            'external_shop_id' => 'shop-dup',
            'credentials_ciphertext' => 'stub',
            'credentials_key_version' => 1,
            'state' => 'active',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function testDifferentExternalShopIdsAreAllowedAcrossDifferentCompanies(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var CompanyRepository $companies */
        $companies = $container->get(CompanyRepository::class);
        /** @var MarketplaceAccountRepository $marketplaceAccounts */
        $marketplaceAccounts = $container->get(MarketplaceAccountRepository::class);

        $companyA = CompanyBuilder::aCompany()->withName('Company A')->persistWith($companies);
        $companyB = CompanyBuilder::aCompany()->withName('Company B')->persistWith($companies);

        // Уникальность (company_id, marketplace, external_shop_id) держит дубль
        // внутри одной компании. Один и тот же external_shop_id у двух разных
        // компаний с этой миграции запрещён отдельным глобальным частичным
        // индексом (uq_marketplace_account_marketplace_external_shop_active,
        // ADR-021) — это покрыто MarketplaceAccountUniquenessTest. Здесь же
        // проверяется, что разные кабинеты у разных компаний не мешают друг
        // другу.
        $accountA = MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($companyA)
            ->withExternalShopId('shop-company-a')
            ->persistWith($companies, $marketplaceAccounts);
        $accountB = MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($companyB)
            ->withExternalShopId('shop-company-b')
            ->persistWith($companies, $marketplaceAccounts);

        self::assertNotSame((string) $accountA->id(), (string) $accountB->id());
        self::assertSame((string) $companyA->id(), (string) $accountA->companyId());
        self::assertSame((string) $companyB->id(), (string) $accountB->companyId());
        self::assertNotSame((string) $accountA->companyId(), (string) $accountB->companyId());
    }
}
