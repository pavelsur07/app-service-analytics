<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ingestion;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\MarketplaceRawDocumentRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Экран подключений: состояние подключения и свежесть его выгрузок.
 * Обязательное покрытие ADR-005 — изоляция данных между компаниями.
 */
final class ListConnectionsControllerTest extends WebTestCase
{
    public function testConnectionCarriesStateAndFreshnessOfEachReport(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);

        $account = MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($company)
            ->withExternalShopId('shop-1')
            ->persistWith($this->companies(), $this->marketplaceAccounts());

        MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()
            ->withCompanyId($company->id())
            ->withMarketplaceAccountId($account->id())
            ->withReceivedAt(new \DateTimeImmutable('2026-08-14 09:00:00'))
            ->persistWith($this->rawDocuments());

        $payload = $this->get($client, $company->id());

        self::assertCount(1, $payload['connections']);
        $connection = $payload['connections'][0];
        self::assertSame('shop-1', $connection['externalShopId']);
        self::assertSame('active', $connection['state']);
        // Свежесть по типу выгрузки, а не одной датой на подключение:
        // экран показывает продажи и каталог отдельно, и по нему видно,
        // что встало именно продажи.
        $loaded = $connection['lastLoadedAt'];
        self::assertIsArray($loaded);
        self::assertArrayHasKey(MarketplaceReportType::OzonPostingFboList, $loaded);
        $salesLoadedAt = $loaded[MarketplaceReportType::OzonPostingFboList];
        self::assertIsString($salesLoadedAt);
        self::assertStringStartsWith('2026-08-14 09:00:00', $salesLoadedAt);
    }

    public function testBrokenConnectionIsVisibleAsSuch(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);

        // Метка в интерфейсе — вторая половина письма из ADR-007:
        // уведомление без места, куда прийти, обрывается на письме.
        MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($company)
            ->withExternalShopId('shop-broken')
            ->withState(MarketplaceAccountState::Broken)
            ->persistWith($this->companies(), $this->marketplaceAccounts());

        $payload = $this->get($client, $company->id());

        self::assertSame('broken', $payload['connections'][0]['state']);
        // Загрузок не было вовсе — и это отдельный факт, а не то же самое,
        // что «сломано».
        self::assertSame([], $payload['connections'][0]['lastLoadedAt']);
    }

    public function testConnectionsOfAnotherCompanyAreNotListed(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);

        MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($company)
            ->withExternalShopId('shop-own')
            ->persistWith($this->companies(), $this->marketplaceAccounts());
        // Чужая компания со своим подключением — доказывает изоляцию,
        // а не только то, что своё подключение попадает в ответ.
        MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($this->companies()))
            ->withExternalShopId('shop-foreign')
            ->persistWith($this->companies(), $this->marketplaceAccounts());

        $payload = $this->get($client, $company->id());

        $shops = array_map(
            static fn (array $connection): mixed => $connection['externalShopId'],
            $payload['connections'],
        );
        self::assertSame(['shop-own'], $shops);
    }

    public function testCredentialsNeverAppearInTheResponse(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);

        MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($company)
            ->withCredentials('SUPER-SECRET-CIPHERTEXT', 1)
            ->persistWith($this->companies(), $this->marketplaceAccounts());

        $content = $this->rawResponse($client, $company->id());

        // Проверка по всему телу, а не по ключам DTO: утечка учётных данных
        // не отменяется правкой фронтенда, поэтому и запрос их не выбирает.
        self::assertStringNotContainsString('SUPER-SECRET-CIPHERTEXT', $content);
        self::assertStringNotContainsString('credentials', $content);
    }

    /**
     * @return array{connections: list<array<string, mixed>>}
     */
    private function get(KernelBrowser $client, Uuid $companyId): array
    {
        /** @var array{connections: list<array<string, mixed>>} $payload */
        $payload = json_decode($this->rawResponse($client, $companyId), true, flags: \JSON_THROW_ON_ERROR);

        return $payload;
    }

    private function rawResponse(KernelBrowser $client, Uuid $companyId): string
    {
        $client->request('GET', "/api/companies/{$companyId->toRfc4122()}/connections");
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        return $content;
    }

    /**
     * Компания заводится здесь же, а не достаётся по id: у CompanyRepository
     * нет чтения по одному лишь идентификатору, и это правильно — §1 ставит
     * companyId первым параметром любого чтения данных компании.
     */
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

    private function marketplaceAccounts(): MarketplaceAccountRepository
    {
        /** @var MarketplaceAccountRepository $accounts */
        $accounts = static::getContainer()->get(MarketplaceAccountRepository::class);

        return $accounts;
    }

    private function rawDocuments(): MarketplaceRawDocumentRepository
    {
        /** @var MarketplaceRawDocumentRepository $rawDocuments */
        $rawDocuments = static::getContainer()->get(MarketplaceRawDocumentRepository::class);

        return $rawDocuments;
    }
}
