<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\Facade\IdentityFacade;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Identity\Infrastructure\Query\ActiveOzonAccountsQuery;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * findActiveOzonSyncTargets — межарендаторное чтение для планировщика
 * (CLAUDE.md §1, «Исключение...»): предмет первого теста именно то,
 * что оно видит все компании разом, это не баг изоляции.
 */
final class IdentityFacadeTest extends KernelTestCase
{
    public function testFindActiveOzonSyncTargetsReturnsOnlyActiveAccountsAcrossCompanies(): void
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

        $targets = $this->identityFacade($container)->findActiveOzonSyncTargets();

        $marketplaceAccountIds = array_map(static fn ($target) => $target->marketplaceAccountId, $targets);
        self::assertContains($activeInCompanyA->id()->toRfc4122(), $marketplaceAccountIds);
        self::assertContains($activeInCompanyB->id()->toRfc4122(), $marketplaceAccountIds);
        self::assertCount(2, $targets);
    }

    /**
     * Громкий отказ, не тихая отдача первых 200 — часть компаний тогда
     * молча перестала бы синхронизироваться.
     */
    public function testFindActiveOzonSyncTargetsFailsLoudlyPastTheSafetyCap(): void
    {
        $container = $this->bootedContainer();
        $companies = $this->companies($container);
        $marketplaceAccounts = $this->marketplaceAccounts($container);

        for ($i = 0; $i <= ActiveOzonAccountsQuery::MAX_RESULTS; ++$i) {
            MarketplaceAccountBuilder::aMarketplaceAccount()
                ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
                ->withExternalShopId("shop-{$i}")
                ->persistWith($companies, $marketplaceAccounts);
        }

        $this->expectException(\RuntimeException::class);

        $this->identityFacade($container)->findActiveOzonSyncTargets();
    }

    private function bootedContainer(): ContainerInterface
    {
        self::bootKernel();

        return self::getContainer();
    }

    private function identityFacade(ContainerInterface $container): IdentityFacade
    {
        /** @var IdentityFacade $facade */
        $facade = $container->get(IdentityFacade::class);

        return $facade;
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
