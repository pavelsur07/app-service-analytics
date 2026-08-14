<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ingestion;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\MarketplaceCredentialsEncryptor;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\OzonCatalogFetcher;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonProductListClient;
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
 * Замена ключей клиентом (ADR-007): проверка у площадки, сохранение,
 * возврат подключения в работу, запись в аудит-журнал.
 *
 * Обращений к настоящему Ozon нет — клиент площадки подменяется (ADR-005).
 */
final class ReplaceConnectionCredentialsControllerTest extends WebTestCase
{
    public function testAcceptedKeyReturnsBrokenConnectionToWork(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->connection($company, MarketplaceAccountState::Broken);
        $this->ozonAnswers(200);

        $this->put($client, $company, $account, ['clientId' => 'shop-1', 'apiKey' => 'fresh-key', 'version' => 1]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        // Сломанное подключение возвращается в работу самой заменой:
        // причина broken была ровно в этих ключах, и требовать второго
        // действия клиент не поймёт.
        self::assertSame('active', $this->state($account));
    }

    public function testRejectedKeyIsNotSavedAndConnectionStaysBroken(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->connection($company, MarketplaceAccountState::Broken);
        $before = $this->ciphertext($account);
        // Площадка отвергла ключ. Сохранить его значило бы вернуть
        // подключение в работу, дать синхронизации упасть на первом
        // запросе и снова сломать его — клиент решил бы, что дело в нас.
        $this->ozonAnswers(401);

        $this->put($client, $company, $account, ['clientId' => 'shop-1', 'apiKey' => 'wrong-key', 'version' => 1]);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame('broken', $this->state($account));
        self::assertSame($before, $this->ciphertext($account));
    }

    public function testReplacementIsWrittenToTheAuditJournal(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->connection($company, MarketplaceAccountState::Active);
        $this->ozonAnswers(200);

        $this->put($client, $company, $account, ['clientId' => 'shop-1', 'apiKey' => 'rotated-key', 'version' => 1]);

        // «Добавление и изменение учётных данных подключений» — одно
        // из четырёх событий, для которых журнал обязателен (CLAUDE.md,
        // «Безопасность и аудит»).
        $record = $this->connectionOf()->fetchAssociative(
            'SELECT action, company_id, subject_id, previous_value, new_value FROM audit_record WHERE subject_id = ?',
            [$account->id()->toRfc4122()],
        );
        self::assertIsArray($record);
        self::assertSame('marketplace_account.credentials_replaced', $record['action']);
        self::assertSame($company->id()->toRfc4122(), $record['company_id']);
        // «Было» и «стало» обязательны (ADR-011) и обязаны различаться —
        // иначе запись не отвечает на вопрос, изменилось ли что-нибудь.
        // И там отпечаток, а не ключ: журнал не место для секрета.
        self::assertIsString($record['previous_value']);
        self::assertIsString($record['new_value']);
        self::assertNotSame($record['previous_value'], $record['new_value']);
        self::assertStringStartsWith('sha256:', $record['new_value']);
    }

    public function testSecretNeverAppearsInTheResponse(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->connection($company, MarketplaceAccountState::Broken);
        $this->ozonAnswers(200);

        $this->put($client, $company, $account, ['clientId' => 'shop-1', 'apiKey' => 'SUPER-SECRET-KEY', 'version' => 1]);

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        // Эхо секрета в ответе оставило бы его в истории запросов браузера
        // и в любом логе прокси на пути.
        self::assertStringNotContainsString('SUPER-SECRET-KEY', $content);
    }

    public function testConnectionOfAnotherCompanyIsNotTouched(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        // Обязательное покрытие ADR-005. Идентификатор подключения виден
        // клиенту на своём экране, и защитой служит только companyId
        // в самом чтении подключения.
        $foreign = $this->connection(
            CompanyBuilder::aCompany()->persistWith($this->companies()),
            MarketplaceAccountState::Broken,
        );
        $this->ozonAnswers(200);

        $this->put($client, $company, $foreign, ['clientId' => 'shop-1', 'apiKey' => 'fresh-key', 'version' => 1]);

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame('broken', $this->state($foreign));
    }

    public function testEmptyKeyIsRejectedBeforeAnyRequestToTheMarketplace(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->connection($company, MarketplaceAccountState::Broken);
        // Клиент площадки не подменяется вовсе: если запрос всё-таки
        // уйдёт, тест упадёт на попытке реального HTTP.
        $this->put($client, $company, $account, ['clientId' => '', 'apiKey' => '', 'version' => 1]);

        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testKeyOfAnotherCabinetIsRejected(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->connection($company, MarketplaceAccountState::Broken);
        // Ключ другого кабинета живой и проверку у площадки прошёл бы.
        // Сохранив его, мы писали бы данные чужого магазина под это
        // подключение — аналитика испортилась бы молча.
        $this->ozonAnswers(200);

        $this->put($client, $company, $account, ['clientId' => 'another-shop', 'apiKey' => 'live-key', 'version' => 1]);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame('broken', $this->state($account));
    }

    public function testStaleVersionIsRejectedWithConflict(): void
    {
        $client = static::createClient();
        // Два запроса в одном тесте: без этого KernelBrowser перезапускает
        // ядро между ними, подменённый клиент площадки исчезает вместе
        // с контейнером, и второй запрос уходит в настоящий Ozon —
        // ровно то, что ADR-005 запрещает.
        $client->disableReboot();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->connection($company, MarketplaceAccountState::Broken);
        $this->ozonAnswers(200);

        // ADR-008: двое открыли форму, первый сохранил. Второй обязан
        // получить конфликт, а не молча затереть чужую правку.
        $this->put($client, $company, $account, ['clientId' => 'shop-1', 'apiKey' => 'first-key', 'version' => 1]);
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $this->put($client, $company, $account, ['clientId' => 'shop-1', 'apiKey' => 'second-key', 'version' => 1]);

        self::assertSame(409, $client->getResponse()->getStatusCode());
    }

    public function testRequestWithoutVersionIsRejected(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->connection($company, MarketplaceAccountState::Broken);

        // «Принимать изменение без версии как безусловное запрещено —
        // это возвращает исходную проблему» (ADR-008). Клиент площадки
        // не подменён: до него запрос дойти не должен.
        $this->put($client, $company, $account, ['clientId' => 'shop-1', 'apiKey' => 'fresh-key']);

        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testRevokedConnectionIsNotRevivedByNewKey(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->connection($company, MarketplaceAccountState::Revoked);
        $this->ozonAnswers(200);

        // Отзыв необратим (ADR-011). Ответить успехом, оставив подключение
        // отключённым, — худший из исходов: клиент уверен, что починил.
        $this->put($client, $company, $account, ['clientId' => 'shop-1', 'apiKey' => 'fresh-key', 'version' => 1]);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame('revoked', $this->state($account));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(KernelBrowser $client, Company $company, MarketplaceAccount $account, array $body): void
    {
        $client->request(
            'PUT',
            "/api/companies/{$company->id()->toRfc4122()}/connections/{$account->id()->toRfc4122()}/credentials",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    private function ozonAnswers(int $status): void
    {
        $body = 200 === $status
            ? '{"result":{"items":[],"total":0,"last_id":""}}'
            : '{"code":16,"message":"unauthenticated"}';

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

    private function connection(Company $company, MarketplaceAccountState $state): MarketplaceAccount
    {
        /** @var MarketplaceCredentialsEncryptor $encryptor */
        $encryptor = static::getContainer()->get(MarketplaceCredentialsEncryptor::class);

        return MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($company)
            ->withState($state)
            // Client-Id и есть external_shop_id: под ним подключение
            // заведено, и ключ другого кабинета сюда попадать не должен.
            ->withExternalShopId('shop-1')
            ->withPlaintextCredentials(['client_id' => 'shop-1', 'api_key' => 'old-key'], $encryptor)
            ->persistWith($this->companies(), $this->marketplaceAccounts());
    }

    private function state(MarketplaceAccount $account): string
    {
        $state = $this->connectionOf()->fetchOne(
            'SELECT state FROM marketplace_account WHERE id = ?',
            [$account->id()->toRfc4122()],
        );
        self::assertIsString($state);

        return $state;
    }

    private function ciphertext(MarketplaceAccount $account): string
    {
        $ciphertext = $this->connectionOf()->fetchOne(
            'SELECT credentials_ciphertext FROM marketplace_account WHERE id = ?',
            [$account->id()->toRfc4122()],
        );
        self::assertIsString($ciphertext);

        return $ciphertext;
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

    private function marketplaceAccounts(): MarketplaceAccountRepository
    {
        /** @var MarketplaceAccountRepository $accounts */
        $accounts = static::getContainer()->get(MarketplaceAccountRepository::class);

        return $accounts;
    }

    private function connectionOf(): Connection
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        return $connection;
    }
}
