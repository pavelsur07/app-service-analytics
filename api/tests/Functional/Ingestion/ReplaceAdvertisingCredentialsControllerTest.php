<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ingestion;

use App\Identity\Application\Facade\IdentityFacade;
use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\MarketplaceCredentialsEncryptor;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Application\Message\FetchOzonAdCampaignStatsMessage;
use App\Ingestion\Domain\MarketplaceListingRepository;
use App\Ingestion\Domain\OzonAdvertisingFetcher;
use App\Ingestion\Infrastructure\Connector\OzonPerformance\OzonPerformanceCampaignClient;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\MarketplaceListingBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Ввод рекламного ключа (ADR-026, п. 1) через HTTP. Сквозь HTTP — потому
 * что проверяется то, чего иначе не проверить: изоляция компаний (членство
 * проверяет подписчик kernel.controller), разбор тела на границе доверия
 * и то, что ключ Seller API подключения не стирается рекламным.
 *
 * Обращений к настоящему Ozon нет — клиент площадки подменяется (ADR-005).
 */
final class ReplaceAdvertisingCredentialsControllerTest extends WebTestCase
{
    private string $actorUserId = '';

    private const string CAMPAIGNS = '{"list":[{"id":"101","state":"CAMPAIGN_STATE_RUNNING","advObjectType":"SKU","createdAt":"2026-09-01T09:08:29Z"}],"total":"1"}';

    public function testAcceptedKeyIsStoredNextToTheSellerKey(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->connection($company, MarketplaceAccountState::Active);
        $this->catalog($company, $account, ['555']);
        $this->stubPerformance(products: '{"products":[{"sku":"555","bid":"0","title":"x"}]}');

        $this->put($client, $company, $account, ['clientId' => 'perf@advertising.performance.ozon.ru', 'clientSecret' => 'perf-secret', 'version' => 1]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('active', $this->column($account, 'advertising_state'));
        // Объект учётных данных дополнен, а не заменён: подключение
        // без ключа Seller API осталось бы без продаж ради рекламы.
        self::assertSame([
            'client_id' => 'shop-1',
            'api_key' => 'seller-key',
            'performance_client_id' => 'perf@advertising.performance.ozon.ru',
            'performance_client_secret' => 'perf-secret',
        ], $this->credentials($account));

        // Первичная загрузка (ADR-026 п. 4) ставится вместе с принятым
        // ключом: иначе реклама молча не грузилась бы до ближайшего тика,
        // а год истории — никогда.
        $chunks = [];
        $transport = static::getContainer()->get('messenger.transport.async_backfill');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof FetchOzonAdCampaignStatsMessage && $message->marketplaceAccountId === $account->id()->toRfc4122()) {
                $chunks[] = $message->from;
            }
        }
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow'));
        self::assertSame($today->modify('-12 months')->format('Y-m-d'), $chunks[\count($chunks) - 1] ?? null);
    }

    public function testSellerKeyReplacementKeepsTheAdvertisingKey(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->connection($company, MarketplaceAccountState::Active);
        $this->catalog($company, $account, ['555']);
        $this->stubPerformance(products: '{"products":[{"sku":"555","bid":"0","title":"x"}]}');
        $this->put($client, $company, $account, ['clientId' => 'perf-id', 'clientSecret' => 'perf-secret', 'version' => 1]);
        self::assertSame(200, $client->getResponse()->getStatusCode());

        // Замена ключа Seller API идёт своим путём и не знает о рекламе;
        // её ключ обязан пережить эту замену. Проба ключа Seller API здесь
        // не предмет, поэтому вызывается сохраняющая часть — Facade.
        /** @var IdentityFacade $identity */
        $identity = static::getContainer()->get(IdentityFacade::class);
        $identity->replaceMarketplaceCredentials(
            $company->id()->toRfc4122(),
            $account->id()->toRfc4122(),
            ['client_id' => 'shop-1', 'api_key' => 'rotated-seller-key'],
            2,
            $this->actorUserId,
        );

        $credentials = $this->credentials($account);
        self::assertSame('rotated-seller-key', $credentials['api_key']);
        self::assertSame('perf-secret', $credentials['performance_client_secret']);
    }

    public function testRejectedKeyIsNotSaved(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->connection($company, MarketplaceAccountState::Active);
        $before = $this->column($account, 'credentials_ciphertext');
        $this->stubPerformance(tokenStatus: 401);

        $this->put($client, $company, $account, ['clientId' => 'perf-id', 'clientSecret' => 'wrong', 'version' => 1]);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame('advertising_credentials_rejected', $this->code($client));
        self::assertNull($this->column($account, 'advertising_state'));
        self::assertSame($before, $this->column($account, 'credentials_ciphertext'));
    }

    public function testMarketplaceUnavailableIsNotAKeyRejection(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->connection($company, MarketplaceAccountState::Active);
        $this->stubPerformance(campaignsStatus: 503);

        $this->put($client, $company, $account, ['clientId' => 'perf-id', 'clientSecret' => 'perf-secret', 'version' => 1]);

        self::assertSame(503, $client->getResponse()->getStatusCode());
        self::assertSame('marketplace_unavailable', $this->code($client));
        self::assertNull($this->column($account, 'advertising_state'));
    }

    /**
     * Performance API не отдаёт Client-Id. Ключ другого кабинета — живой,
     * пробу бы прошёл, а его цифры записались бы под этот магазин.
     */
    public function testKeyWhoseCampaignProductsAreNotInTheCatalogIsRefused(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->connection($company, MarketplaceAccountState::Active);
        $this->catalog($company, $account, ['555']);
        $this->stubPerformance(products: '{"products":[{"sku":"999","bid":"0","title":"x"}]}');

        $this->put($client, $company, $account, ['clientId' => 'perf-id', 'clientSecret' => 'perf-secret', 'version' => 1]);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame('advertising_credentials_of_another_cabinet', $this->code($client));
        self::assertNull($this->column($account, 'advertising_state'));
    }

    /**
     * Ключ вводят сразу после подключения, до первой загрузки каталога:
     * проверять не с чем, и это не повод считать ключ чужим.
     */
    public function testEmptyCatalogDoesNotRefuseTheKey(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->connection($company, MarketplaceAccountState::Active);
        $this->stubPerformance(products: '{"products":[{"sku":"999","bid":"0","title":"x"}]}');

        $this->put($client, $company, $account, ['clientId' => 'perf-id', 'clientSecret' => 'perf-secret', 'version' => 1]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('active', $this->column($account, 'advertising_state'));
    }

    /**
     * Снятая разведкой причина — архивные товары: такая кампания просто
     * не участвует в сверке.
     */
    public function testCampaignWithArchivedProductsIsSkipped(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->connection($company, MarketplaceAccountState::Active);
        $this->catalog($company, $account, ['555']);
        $this->stubPerformance(
            products: '{"error":"Товары перенесены в архив. Для добавления или изменения товаров сначала верните кампанию и товары из архива"}',
            productsStatus: 400,
        );

        $this->put($client, $company, $account, ['clientId' => 'perf-id', 'clientSecret' => 'perf-secret', 'version' => 1]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * Любой другой отказ сверку не пропускает: ключ, проверенный
     * наполовину, не сохраняется.
     */
    public function testOtherProductsRefusalIsNotSkipped(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->connection($company, MarketplaceAccountState::Active);
        $this->catalog($company, $account, ['555']);
        $this->stubPerformance(products: '{"error":"bad request"}', productsStatus: 400);

        $this->put($client, $company, $account, ['clientId' => 'perf-id', 'clientSecret' => 'perf-secret', 'version' => 1]);

        self::assertSame(503, $client->getResponse()->getStatusCode());
        self::assertNull($this->column($account, 'advertising_state'));
    }

    public function testConnectionOfAnotherCompanyIsNotTouched(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        // Обязательное покрытие ADR-005: идентификатор чужого подключения
        // не даёт записать в него ключ.
        $foreign = $this->connection(
            CompanyBuilder::aCompany()->persistWith($this->companies()),
            MarketplaceAccountState::Active,
        );
        $before = $this->column($foreign, 'credentials_ciphertext');
        $this->stubPerformance();

        $this->put($client, $company, $foreign, ['clientId' => 'perf-id', 'clientSecret' => 'perf-secret', 'version' => 1]);

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertNull($this->column($foreign, 'advertising_state'));
        self::assertSame($before, $this->column($foreign, 'credentials_ciphertext'));
    }

    public function testSecretNeverAppearsInTheResponseOrTheAuditJournal(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->connection($company, MarketplaceAccountState::Active);
        $this->stubPerformance();

        $this->put($client, $company, $account, ['clientId' => 'perf-id', 'clientSecret' => 'SUPER-SECRET-PERF', 'version' => 1]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringNotContainsString('SUPER-SECRET-PERF', $content);

        // ADR-007: добавление учётных данных подключения — событие журнала.
        $record = $this->connectionOf()->fetchAssociative(
            'SELECT action, previous_value, new_value FROM audit_record WHERE subject_id = ? AND action = ?',
            [$account->id()->toRfc4122(), 'marketplace_account.advertising_credentials_replaced'],
        );
        self::assertIsArray($record);
        self::assertNull($record['previous_value']);
        self::assertIsString($record['new_value']);
        self::assertStringStartsWith('perf-id (sha256:', $record['new_value']);
        self::assertStringNotContainsString('SUPER-SECRET-PERF', $record['new_value']);
    }

    public function testMissingSecretIsRejectedBeforeAnyRequestToTheMarketplace(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->connection($company, MarketplaceAccountState::Active);
        $this->stubPerformance(tokenStatus: 500);

        $this->put($client, $company, $account, ['clientId' => 'perf-id', 'clientSecret' => '  ', 'version' => 1]);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame('client_secret_required', $this->code($client));
    }

    public function testRevokedConnectionGetsNoAdvertisingKey(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->connection($company, MarketplaceAccountState::Revoked);
        $this->stubPerformance();

        $this->put($client, $company, $account, ['clientId' => 'perf-id', 'clientSecret' => 'perf-secret', 'version' => 1]);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame('connection_revoked', $this->code($client));
        self::assertNull($this->column($account, 'advertising_state'));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(KernelBrowser $client, Company $company, MarketplaceAccount $account, array $body): void
    {
        $client->request(
            'PUT',
            "/api/companies/{$company->id()->toRfc4122()}/connections/{$account->id()->toRfc4122()}/advertising-credentials",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    private function stubPerformance(
        int $tokenStatus = 200,
        int $campaignsStatus = 200,
        string $products = '{"products":[]}',
        int $productsStatus = 200,
    ): void {
        static::getContainer()->set(OzonPerformanceCampaignClient::class, new class($tokenStatus, $campaignsStatus, self::CAMPAIGNS, $products, $productsStatus) implements OzonAdvertisingFetcher {
            public function __construct(
                private readonly int $tokenStatus,
                private readonly int $campaignsStatus,
                private readonly string $campaigns,
                private readonly string $products,
                private readonly int $productsStatus,
            ) {
            }

            public function token(string $clientId, string $clientSecret): string
            {
                $this->respond($this->tokenStatus);

                return 'jwt';
            }

            public function expense(string $token, \DateTimeImmutable $from, \DateTimeImmutable $to): string
            {
                throw new \LogicException('Проба ключа статистику не запрашивает.');
            }

            public function productsSku(string $token, array $campaignIds, \DateTimeImmutable $day): string
            {
                throw new \LogicException('Проба ключа статистику не запрашивает.');
            }

            public function orderSkuReport(string $token, array $campaignIds, \DateTimeImmutable $from, \DateTimeImmutable $to): string
            {
                throw new \LogicException('Проба ключа отчёты не заказывает.');
            }

            public function orderCpoOrdersReport(string $token, \DateTimeImmutable $from, \DateTimeImmutable $to): string
            {
                throw new \LogicException('Проба ключа отчёты не заказывает.');
            }

            public function reportState(string $token, string $uuid): string
            {
                throw new \LogicException('Проба ключа отчёты не заказывает.');
            }

            public function report(string $token, string $uuid): string
            {
                throw new \LogicException('Проба ключа отчёты не заказывает.');
            }

            public function daily(string $token, \DateTimeImmutable $from, \DateTimeImmutable $to): string
            {
                throw new \LogicException('Проба ключа статистику не запрашивает.');
            }

            public function campaigns(string $token): string
            {
                $this->respond($this->campaignsStatus);

                return $this->campaigns;
            }

            public function campaignProducts(string $token, string $campaignId): string
            {
                if (200 !== $this->productsStatus) {
                    return (new MockHttpClient(new MockResponse($this->products, ['http_code' => $this->productsStatus])))
                        ->request('GET', 'https://api-performance.ozon.ru/api/client/campaign/1/v2/products')
                        ->getContent();
                }

                return $this->products;
            }

            private function respond(int $status): void
            {
                // Настоящий отказ symfony/http-client: у него тот же класс
                // исключения и тот же код, по которым сценарий классифицирует
                // отказ, а не имитация.
                (new MockHttpClient(new MockResponse('{"error":"x"}', ['http_code' => $status])))
                    ->request('GET', 'https://api-performance.ozon.ru/api/client/campaign')
                    ->getContent();
            }
        });
    }

    /**
     * @param list<string> $skus
     */
    private function catalog(Company $company, MarketplaceAccount $account, array $skus): void
    {
        /** @var MarketplaceListingRepository $listings */
        $listings = static::getContainer()->get(MarketplaceListingRepository::class);
        $listings->replaceForAccount($company->id()->toRfc4122(), $account->id(), array_map(
            static fn (string $sku) => MarketplaceListingBuilder::aMarketplaceListing()
                ->withCompanyId($company->id())
                ->withMarketplaceAccountId($account->id())
                ->withMarketplaceSku($sku)
                ->build(),
            $skus,
        ));
    }

    private function connection(Company $company, MarketplaceAccountState $state): MarketplaceAccount
    {
        return MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($company)
            ->withState($state)
            ->withExternalShopId('shop-1')
            ->withPlaintextCredentials(['client_id' => 'shop-1', 'api_key' => 'seller-key'], $this->encryptor())
            ->persistWith($this->companies(), $this->marketplaceAccounts());
    }

    /**
     * @return array<string, string>
     */
    private function credentials(MarketplaceAccount $account): array
    {
        $row = $this->connectionOf()->fetchAssociative(
            'SELECT credentials_ciphertext, credentials_key_version FROM marketplace_account WHERE id = ?',
            [$account->id()->toRfc4122()],
        );
        self::assertIsArray($row);
        self::assertIsString($row['credentials_ciphertext']);
        self::assertIsInt($row['credentials_key_version']);

        return $this->encryptor()->decrypt($row['credentials_ciphertext'], $row['credentials_key_version'])->toArray();
    }

    private function column(MarketplaceAccount $account, string $column): mixed
    {
        return $this->connectionOf()->fetchOne(
            "SELECT {$column} FROM marketplace_account WHERE id = ?",
            [$account->id()->toRfc4122()],
        );
    }

    private function code(KernelBrowser $client): string
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        /** @var array{code: string} $payload */
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        return $payload['code'];
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
        $this->actorUserId = $user->id()->toRfc4122();

        return $company;
    }

    private function encryptor(): MarketplaceCredentialsEncryptor
    {
        /** @var MarketplaceCredentialsEncryptor $encryptor */
        $encryptor = static::getContainer()->get(MarketplaceCredentialsEncryptor::class);

        return $encryptor;
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
