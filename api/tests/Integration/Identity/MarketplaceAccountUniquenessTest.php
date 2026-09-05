<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Один кабинет Ozon не подключается в две наши компании (ADR-021).
 * Проверка глобальная, и держит её индекс, а не запрос перед вставкой:
 * два параллельных запроса прошли бы проверку оба (CLAUDE.md §4).
 */
final class MarketplaceAccountUniquenessTest extends KernelTestCase
{
    public function testSameCabinetCannotBeConnectedToASecondCompany(): void
    {
        $companies = $this->companies();
        $accounts = $this->marketplaceAccounts();

        MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
            ->withExternalShopId('shop-42')
            ->persistWith($companies, $accounts);

        $this->expectException(UniqueConstraintViolationException::class);

        MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
            ->withExternalShopId('shop-42')
            ->persistWith($companies, $accounts);
    }

    public function testRevokedCabinetIsFreedForAnotherCompany(): void
    {
        $companies = $this->companies();
        $accounts = $this->marketplaceAccounts();

        // Отзыв необратим (ADR-011), поэтому безусловный индекс занял бы
        // кабинет навсегда: клиент, отключившийся и вернувшийся, упёрся
        // бы в стену, разбирать которую пришлось бы руками.
        MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
            ->withExternalShopId('shop-77')
            ->withState(MarketplaceAccountState::Revoked)
            ->persistWith($companies, $accounts);

        $second = MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
            ->withExternalShopId('shop-77')
            ->persistWith($companies, $accounts);

        self::assertSame('shop-77', $second->externalShopId());
    }

    private function companies(): CompanyRepository
    {
        $companies = static::getContainer()->get(CompanyRepository::class);
        self::assertInstanceOf(CompanyRepository::class, $companies);

        return $companies;
    }

    private function marketplaceAccounts(): MarketplaceAccountRepository
    {
        $accounts = static::getContainer()->get(MarketplaceAccountRepository::class);
        self::assertInstanceOf(MarketplaceAccountRepository::class, $accounts);

        return $accounts;
    }
}
