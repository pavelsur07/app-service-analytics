<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ingestion;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\OzonCatalogFetcher;
use App\Ingestion\Domain\OzonExpensesFetcher;
use App\Ingestion\Domain\OzonPostingsFetcher;
use App\Ingestion\Domain\OzonReturnsFetcher;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonAccrualByDayClient;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonPostingFboListClient;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonProductListClient;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonReturnsListClient;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Приём подключения на онбординге (ADR-021).
 *
 * Через HTTP проверяется то, что иначе проверить нечем: изоляция
 * арендаторов живёт в подписчике kernel.controller, а повтор запроса
 * и есть предмет проверки идемпотентности приёма (CLAUDE.md §9).
 */
final class ConnectOzonAccountControllerTest extends WebTestCase
{
    public function testForeignCompanyIsRejectedAndNothingIsWritten(): void
    {
        $client = static::createClient();
        $this->loginAsCompanyMember($client);
        // Обязательное покрытие §9: изоляция данных между компаниями.
        $foreign = CompanyBuilder::aCompany()->persistWith($this->companies());
        $this->allScopesSucceed();

        $this->post($client, $foreign, ['name' => 'Чужой магазин', 'clientId' => 'shop-9', 'apiKey' => 'live-key']);

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->accountCount($foreign->id()->toRfc4122()));
    }

    public function testAcceptedKeyCreatesTheConnection(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $this->allScopesSucceed();

        $this->post($client, $company, ['name' => 'Мой магазин', 'clientId' => 'shop-1', 'apiKey' => 'live-key']);

        self::assertSame(201, $client->getResponse()->getStatusCode());
        self::assertSame(1, $this->accountCount($company->id()->toRfc4122()));
    }

    public function testRejectedKeyAnswersWithItsOwnCode(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $this->stubCatalog(401);

        $this->post($client, $company, ['name' => 'Мой магазин', 'clientId' => 'shop-1', 'apiKey' => 'wrong-key']);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame('credentials_rejected', $this->code($client));
    }

    /**
     * Боевой инцидент: единственная проба каталога проходила, а ключ был
     * без права на продажи, — подключение оживало и падало через секунды
     * на первой реальной синхронизации отправлений.
     */
    public function testSalesScopeRejectionAnswersWithItsOwnCode(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $this->stubCatalog(200);
        $this->stubPostings(401);

        $this->post($client, $company, ['name' => 'Мой магазин', 'clientId' => 'shop-1', 'apiKey' => 'sales-scope-missing']);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame('credentials_rejected_sales', $this->code($client));
        self::assertSame(0, $this->accountCount($company->id()->toRfc4122()));
    }

    public function testExpensesScopeRejectionAnswersWithItsOwnCode(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $this->stubCatalog(200);
        $this->stubPostings(200);
        $this->stubExpenses(403);

        $this->post($client, $company, ['name' => 'Мой магазин', 'clientId' => 'shop-1', 'apiKey' => 'expenses-scope-missing']);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame('credentials_rejected_expenses', $this->code($client));
        self::assertSame(0, $this->accountCount($company->id()->toRfc4122()));
    }

    public function testReturnsScopeRejectionAnswersWithItsOwnCode(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $this->stubCatalog(200);
        $this->stubPostings(200);
        $this->stubExpenses(200);
        $this->stubReturns(401);

        $this->post($client, $company, ['name' => 'Мой магазин', 'clientId' => 'shop-1', 'apiKey' => 'returns-scope-missing']);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame('credentials_rejected_returns', $this->code($client));
        self::assertSame(0, $this->accountCount($company->id()->toRfc4122()));
    }

    public function testUnavailableMarketplaceAnswersWithItsOwnCode(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $this->stubCatalog(503);

        $this->post($client, $company, ['name' => 'Мой магазин', 'clientId' => 'shop-1', 'apiKey' => 'live-key']);

        // Отдельный код и отдельный статус: клиенту надо подождать,
        // а не выпускать новый ключ (ADR-021).
        self::assertSame(503, $client->getResponse()->getStatusCode());
        self::assertSame('marketplace_unavailable', $this->code($client));
    }

    public function testUnavailableOnALaterProbeIsStillUnavailable(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        // 429/5xx на любой пробе — недоступность площадки, а не отказ
        // права, независимо от того, какая по счёту это проба.
        $this->stubCatalog(200);
        $this->stubPostings(200);
        $this->stubExpenses(429);

        $this->post($client, $company, ['name' => 'Мой магазин', 'clientId' => 'shop-1', 'apiKey' => 'live-key']);

        self::assertSame(503, $client->getResponse()->getStatusCode());
        self::assertSame('marketplace_unavailable', $this->code($client));
        self::assertSame(0, $this->accountCount($company->id()->toRfc4122()));
    }

    public function testRepeatedRequestDoesNotCreateASecondConnection(): void
    {
        $client = static::createClient();
        // Без disableReboot ядро перезапускается между запросами,
        // подменённый клиент площадки исчезает вместе с контейнером,
        // и второй запрос уходит в настоящий Ozon (ADR-005).
        $client->disableReboot();
        $company = $this->loginAsCompanyMember($client);
        $this->allScopesSucceed();

        $this->post($client, $company, ['name' => 'Мой магазин', 'clientId' => 'shop-1', 'apiKey' => 'live-key']);
        self::assertSame(201, $client->getResponse()->getStatusCode());

        $this->post($client, $company, ['name' => 'Мой магазин', 'clientId' => 'shop-1', 'apiKey' => 'live-key']);

        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertSame('cabinet_already_connected', $this->code($client));
        self::assertSame(1, $this->accountCount($company->id()->toRfc4122()));
    }

    public function testCabinetTakenByAnotherCompanyIsRefused(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($this->companies()))
            ->withExternalShopId('shop-taken')
            ->persistWith($this->companies(), $this->marketplaceAccounts());
        $this->allScopesSucceed();

        // Без этой проверки первый же клиент, продублировавший кабинет
        // на второй аккаунт, получил бы две компании с одними фактами
        // и расхождение, которое нечем объяснить (ADR-021).
        $this->post($client, $company, ['name' => 'Второй магазин', 'clientId' => 'shop-taken', 'apiKey' => 'live-key']);

        self::assertSame(409, $client->getResponse()->getStatusCode());
    }

    public function testEmptyFieldsAreRejectedBeforeAnyRequestToTheMarketplace(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        // Клиент площадки не подменяется: если запрос всё-таки уйдёт,
        // тест упадёт на попытке реального HTTP.
        $this->post($client, $company, ['name' => '', 'clientId' => '', 'apiKey' => '']);

        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testSecretNeverAppearsInTheResponse(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $this->allScopesSucceed();

        $this->post($client, $company, ['name' => 'Мой магазин', 'clientId' => 'shop-1', 'apiKey' => 'SUPER-SECRET-KEY']);

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringNotContainsString('SUPER-SECRET-KEY', $content);
    }

    /** @param array<string, mixed> $body */
    private function post(KernelBrowser $client, Company $company, array $body): void
    {
        $client->request(
            'POST',
            "/api/companies/{$company->id()->toRfc4122()}/connections",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    private function code(KernelBrowser $client): string
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        $decoded = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('code', $decoded);
        self::assertIsString($decoded['code']);

        return $decoded['code'];
    }

    private function accountCount(string $companyId): int
    {
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        $count = $connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_account WHERE company_id = ?',
            [$companyId],
        );
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function allScopesSucceed(): void
    {
        $this->stubCatalog(200);
        $this->stubPostings(200);
        $this->stubExpenses(200);
        $this->stubReturns(200);
    }

    private function stubCatalog(int $status): void
    {
        $body = $this->bodyFor($status);
        static::getContainer()->set(OzonProductListClient::class, new class($body, $status) implements OzonCatalogFetcher {
            public function __construct(
                private readonly string $body,
                private readonly int $status,
            ) {
            }

            public function fetchPage(string $clientId, string $apiKey, string $lastId, int $limit = 1000): string
            {
                $client = new MockHttpClient(new MockResponse($this->body, ['http_code' => $this->status]));

                return $client->request('POST', 'https://api-seller.ozon.ru/v3/product/list')->getContent();
            }
        });
    }

    private function stubPostings(int $status): void
    {
        $body = $this->bodyFor($status);
        static::getContainer()->set(OzonPostingFboListClient::class, new class($body, $status) implements OzonPostingsFetcher {
            public function __construct(
                private readonly string $body,
                private readonly int $status,
            ) {
            }

            public function fetch(string $clientId, string $apiKey, \DateTimeImmutable $since, \DateTimeImmutable $to): string
            {
                $client = new MockHttpClient(new MockResponse($this->body, ['http_code' => $this->status]));

                return $client->request('POST', 'https://api-seller.ozon.ru/v2/posting/fbo/list')->getContent();
            }
        });
    }

    private function stubExpenses(int $status): void
    {
        $body = $this->bodyFor($status);
        static::getContainer()->set(OzonAccrualByDayClient::class, new class($body, $status) implements OzonExpensesFetcher {
            public function __construct(
                private readonly string $body,
                private readonly int $status,
            ) {
            }

            public function fetchDay(string $clientId, string $apiKey, \DateTimeImmutable $day, string $lastId): string
            {
                $client = new MockHttpClient(new MockResponse($this->body, ['http_code' => $this->status]));

                return $client->request('POST', 'https://api-seller.ozon.ru/v1/finance/accrual/by-day')->getContent();
            }
        });
    }

    private function stubReturns(int $status): void
    {
        $body = $this->bodyFor($status);
        static::getContainer()->set(OzonReturnsListClient::class, new class($body, $status) implements OzonReturnsFetcher {
            public function __construct(
                private readonly string $body,
                private readonly int $status,
            ) {
            }

            public function fetchPage(
                string $clientId,
                string $apiKey,
                \DateTimeImmutable $from,
                \DateTimeImmutable $to,
                int $lastId,
                int $limit = self::MAX_LIMIT,
            ): string {
                $client = new MockHttpClient(new MockResponse($this->body, ['http_code' => $this->status]));

                return $client->request('POST', 'https://api-seller.ozon.ru/v1/returns/list')->getContent();
            }
        });
    }

    private function bodyFor(int $status): string
    {
        return 200 === $status
            ? '{"result":{"items":[],"total":0,"last_id":""}}'
            : '{"code":16,"message":"unauthenticated"}';
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

    private function loginAsCompanyMember(KernelBrowser $client): Company
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $users = new DoctrineUserRepository($entityManager);
        $company = CompanyBuilder::aCompany()->persistWith($this->companies());
        $user = UserBuilder::aUser()->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()
            ->withCompany($company)
            ->withUser($user)
            ->persistWith($this->companies(), $users, new DoctrineCompanyMemberRepository($entityManager));

        $client->loginUser($user, 'api');

        return $company;
    }
}
