<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\ValueObject\Marketplace;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * findAllActive — единственное межарендаторное чтение в этом репозитории
 * (CLAUDE.md §1, обоснование — докблок метода): предмет теста именно
 * то, что оно видит все компании разом, это не баг изоляции.
 */
final class DoctrineMarketplaceAccountRepositoryTest extends KernelTestCase
{
    public function testFindAllActiveReturnsOnlyActiveAccountsAcrossCompanies(): void
    {
        $container = $this->bootedContainer();
        $companies = $this->companies($container);
        $marketplaceAccounts = $this->marketplaceAccounts($container);

        $activeInCompanyA = MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
            ->withExternalShopId('shop-a')
            ->persistWith($companies, $marketplaceAccounts);
        $activeInCompanyB = MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
            ->withExternalShopId('shop-b')
            ->persistWith($companies, $marketplaceAccounts);
        MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
            ->withExternalShopId('shop-broken')
            ->withState(MarketplaceAccountState::Broken)
            ->persistWith($companies, $marketplaceAccounts);
        MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
            ->withExternalShopId('shop-revoked')
            ->withState(MarketplaceAccountState::Revoked)
            ->persistWith($companies, $marketplaceAccounts);

        $result = $marketplaceAccounts->findAllActive(Marketplace::Ozon);

        $resultIds = array_map(static fn ($account) => $account->id()->toRfc4122(), $result);
        self::assertContains($activeInCompanyA->id()->toRfc4122(), $resultIds);
        self::assertContains($activeInCompanyB->id()->toRfc4122(), $resultIds);
        self::assertCount(2, $result);
    }

    private function bootedContainer(): ContainerInterface
    {
        self::bootKernel();

        return self::getContainer();
    }

    private function companies(ContainerInterface $container): CompanyRepository
    {
        /** @var CompanyRepository $companies */
        $companies = $container->get(CompanyRepository::class);

        return $companies;
    }

    private function marketplaceAccounts(ContainerInterface $container): MarketplaceAccountRepository
    {
        /** @var MarketplaceAccountRepository $marketplaceAccounts */
        $marketplaceAccounts = $container->get(MarketplaceAccountRepository::class);

        return $marketplaceAccounts;
    }
}
