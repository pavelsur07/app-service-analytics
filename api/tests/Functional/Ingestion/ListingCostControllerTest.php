<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ingestion;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\MarketplaceListingRepository;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceListingBuilder;
use App\Tests\Support\Builder\MarketplaceListingCostBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Через HTTP проверяется только изоляция арендаторов — обязательное
 * покрытие ADR-005, и проверить его можно лишь там, где есть маршрут
 * и сессия. Всё остальное уровнем ниже: сценарий в ListingCostTest,
 * разбор тела в ListingCostRequestTest. Контроллеры §9 тестировать
 * запрещает, и покрытие изоляции этого запрета не отменяет.
 */
final class ListingCostControllerTest extends WebTestCase
{
    private const string SKU = '220280923';

    public function testListShowsOwnListingsWithCoverage(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = Uuid::v7();

        // Оба артикула одним вызовом: replaceForAccount заменяет каталог
        // подключения целиком, и два вызова подряд стёрли бы первый.
        $this->listings($company, $account, [self::SKU, '111']);
        $this->cost($company, $account, self::SKU);

        $payload = $this->get($client, $company);

        self::assertCount(2, $payload['items']);
        // «Задано у 1 из 2» — единственный честный ответ на вопрос
        // «почему прибыль не сходится».
        self::assertSame(2, $payload['listingCount']);
        self::assertSame(1, $payload['pricedCount']);
    }

    public function testListingsOfAnotherCompanyAreNotShown(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $foreign = CompanyBuilder::aCompany()->persistWith($this->companies());

        $this->listings($company, Uuid::v7(), [self::SKU]);
        $this->listings($foreign, Uuid::v7(), ['999']);

        $payload = $this->get($client, $company);

        self::assertCount(1, $payload['items']);
        self::assertSame(1, $payload['listingCount']);
    }

    public function testCorrectingCostOfAnotherCompanyIsNotFound(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $foreign = CompanyBuilder::aCompany()->persistWith($this->companies());
        $foreignCost = $this->cost($foreign, Uuid::v7(), self::SKU);

        // Себестоимость — коммерческая тайна: зная идентификатор, чужую
        // позицию нельзя ни прочитать, ни исправить.
        $client->request(
            'PUT',
            "/api/companies/{$company->id()->toRfc4122()}/listing-costs/{$foreignCost}",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['unitCostMinor' => 1, 'currency' => 'RUB', 'version' => 1], \JSON_THROW_ON_ERROR),
        );

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * @return array{items: list<array<string, mixed>>, listingCount: int, pricedCount: int}
     */
    private function get(KernelBrowser $client, Company $company): array
    {
        $client->catchExceptions(false);
        $client->request('GET', "/api/companies/{$company->id()->toRfc4122()}/listing-costs");
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array{items: list<array<string, mixed>>, listingCount: int, pricedCount: int} $payload */
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        return $payload;
    }

    /**
     * @param list<string> $skus
     */
    private function listings(Company $company, Uuid $accountId, array $skus): void
    {
        /** @var MarketplaceListingRepository $listings */
        $listings = static::getContainer()->get(MarketplaceListingRepository::class);

        $listings->replaceForAccount(
            $company->id()->toRfc4122(),
            $accountId,
            array_map(
                static fn (string $sku) => MarketplaceListingBuilder::aMarketplaceListing()
                    ->withCompanyId($company->id())
                    ->withMarketplaceAccountId($accountId)
                    ->withMarketplaceSku($sku)
                    ->build(),
                $skus,
            ),
        );
    }

    private function cost(Company $company, Uuid $accountId, string $sku): string
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        return MarketplaceListingCostBuilder::aMarketplaceListingCost()
            ->withCompanyId($company->id())
            ->withMarketplaceAccountId($accountId)
            ->withMarketplaceSku($sku)
            ->withEffectiveFrom(new \DateTimeImmutable('2026-01-01'))
            ->withUnitCost(Money::ofMinor(42_000, 'RUB'))
            ->persistWith(new \App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceListingCostRepository($entityManager))
            ->id()
            ->toRfc4122();
    }

    private function loginAsCompanyMember(KernelBrowser $client): Company
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $users = new DoctrineUserRepository($entityManager);
        $companyMembers = new DoctrineCompanyMemberRepository($entityManager);

        $company = CompanyBuilder::aCompany()->persistWith($this->companies());
        $user = UserBuilder::aUser()->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()
            ->withCompany($company)
            ->withUser($user)
            ->persistWith($this->companies(), $users, $companyMembers);

        $client->loginUser($user, 'api');

        return $company;
    }

    private function companies(): CompanyRepository
    {
        /** @var CompanyRepository $companies */
        $companies = static::getContainer()->get(CompanyRepository::class);

        return $companies;
    }
}
