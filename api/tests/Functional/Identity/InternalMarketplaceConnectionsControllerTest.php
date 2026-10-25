<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\ReplaceAdvertisingCredentialsAction;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\MarketplaceCredentialsEncryptor;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use Monolog\Handler\TestHandler;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Выдача подключений сервисам вне монолита — сервисный контур
 * (CLAUDE.md §1, ADR-043 п. 4). Через HTTP, потому что предмет —
 * граница доверия: firewall с токеном, межарендаторный ответ с ключами
 * и его контракт (services/AGENTS.md §3: PHP проверяет свой ответ
 * по schema.json). Удали эти тесты — и о выдаче ключей без токена
 * или о расхождении с контрактом сервиса мы узнаем только на проде.
 *
 * Закрытие пути снаружи держит nginx (404 на публичных vhost) — его
 * PHPUnit не видит, оно проверяется на конфигурации nginx.
 */
final class InternalMarketplaceConnectionsControllerTest extends WebTestCase
{
    private const string URL = '/api/internal/marketplace-connections?provider=ozon';

    private const string TOKEN = 'test-internal-service-token-0123456789abcdef';

    private const string SCHEMA = '/var/www/services/_contracts/marketplace-connections/v1/schema.json';

    public function testWithoutTokenNothingIsIssued(): void
    {
        $client = static::createClient();
        $this->sellerOnlyAccount('no-token-shop', 'no-token-key');

        $client->request('GET', self::URL);

        self::assertResponseStatusCodeSame(401);
        self::assertStringNotContainsString('no-token-key', (string) $client->getResponse()->getContent());
    }

    /**
     * Чужой токен, токен обратного направления «PHP → сервис» и токен,
     * совпадающий с настоящим только началом, — один и тот же отказ.
     */
    public function testForeignOrNearMissTokenIsRejected(): void
    {
        $client = static::createClient();
        $this->sellerOnlyAccount('foreign-token-shop', 'foreign-token-key');

        foreach ([
            'another-service-token-0123456789abcdefghij',
            'php-to-service-direction-token-0123456789ab',
            substr(self::TOKEN, 0, -1),
            self::TOKEN.'x',
        ] as $token) {
            $client->request('GET', self::URL, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

            self::assertResponseStatusCodeSame(401);
            self::assertSame('invalid_service_token', $this->decode($client)['code']);
            self::assertStringNotContainsString('foreign-token-key', (string) $client->getResponse()->getContent());
        }
    }

    /**
     * Сессия продавца — не учётные данные сервисного контура: firewall
     * без сессии, и вход в приложение ключей не открывает.
     */
    public function testSellerSessionDoesNotOpenTheServiceContour(): void
    {
        $client = static::createClient();
        $this->sellerOnlyAccount('session-shop', 'session-key');

        $client->request('GET', self::URL, server: ['HTTP_COOKIE' => 'PHPSESSID=not-a-service-credential']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testIssuesActiveConnectionsOfAllCompaniesByContract(): void
    {
        $client = static::createClient();
        $connectedAt = new \DateTimeImmutable('2026-10-11 06:00:00', new \DateTimeZone('UTC'));
        $seller = $this->sellerOnlyAccount('seller-shop', 'seller-key', $connectedAt);
        $withAds = $this->accountWithAdvertising('ads-shop', 'ads-key', 'perf-client', 'perf-secret');
        $adsBroken = $this->accountWithAdvertising('ads-broken-shop', 'ads-broken-key', 'perf-broken-client', 'perf-broken-secret');
        $this->marketplaceAccounts()->markAdvertisingBrokenIfActive($adsBroken->companyId()->toRfc4122(), $adsBroken->id(), $adsBroken->version());
        $revoked = $this->sellerOnlyAccount('revoked-shop', 'revoked-key', state: MarketplaceAccountState::Revoked);
        $broken = $this->sellerOnlyAccount('state-broken-shop', 'state-broken-secret', state: MarketplaceAccountState::Broken);

        $client->request('GET', self::URL.'&limit=200', server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $body = (string) $client->getResponse()->getContent();
        $this->assertMatchesContract($body);

        $payload = $this->decode($client);
        self::assertNull($payload['next_cursor']);
        $byId = $this->connectionsById($payload);

        // Разные компании — в одном ответе: межарендаторное чтение §1.
        self::assertSame($seller->companyId()->toRfc4122(), $byId[$seller->id()->toRfc4122()]['company_id']);
        self::assertSame($withAds->companyId()->toRfc4122(), $byId[$withAds->id()->toRfc4122()]['company_id']);
        self::assertNotSame($seller->companyId()->toRfc4122(), $withAds->companyId()->toRfc4122());

        self::assertSame([
            'seller' => ['client_id' => 'seller-shop', 'api_key' => 'seller-key'],
            'performance' => null,
        ], $byId[$seller->id()->toRfc4122()]['credentials']);
        self::assertSame('2026-10-11T06:00:00+00:00', $byId[$seller->id()->toRfc4122()]['updated_at']);
        self::assertSame(['ozon.finance.accrual-by-day', 'ozon.orders.fbo', 'ozon.products.list', 'ozon.products.info'], $byId[$seller->id()->toRfc4122()]['resource_types']);

        self::assertSame([
            'seller' => ['client_id' => 'ads-shop', 'api_key' => 'ads-key'],
            'performance' => ['client_id' => 'perf-client', 'client_secret' => 'perf-secret'],
        ], $byId[$withAds->id()->toRfc4122()]['credentials']);
        self::assertSame([
            'ozon.finance.accrual-by-day', 'ozon.orders.fbo', 'ozon.products.list', 'ozon.products.info',
            'ozon.ads.campaigns', 'ozon.ads.expense', 'ozon.ads.daily',
        ], $byId[$withAds->id()->toRfc4122()]['resource_types']);

        // Сломанная реклама: ключ лежит в шифротексте, но не отдаётся.
        self::assertSame([
            'seller' => ['client_id' => 'ads-broken-shop', 'api_key' => 'ads-broken-key'],
            'performance' => null,
        ], $byId[$adsBroken->id()->toRfc4122()]['credentials']);
        self::assertSame(['ozon.finance.accrual-by-day', 'ozon.orders.fbo', 'ozon.products.list', 'ozon.products.info'], $byId[$adsBroken->id()->toRfc4122()]['resource_types']);
        self::assertStringNotContainsString('perf-broken-secret', $body);

        // Отозванное и сломанное подключение не выдаются: так отзыв
        // и broken доходят до сервиса.
        self::assertArrayNotHasKey($revoked->id()->toRfc4122(), $byId);
        self::assertArrayNotHasKey($broken->id()->toRfc4122(), $byId);
        self::assertStringNotContainsString('revoked-key', $body);
        self::assertStringNotContainsString('state-broken-secret', $body);
    }

    /**
     * Строка аудита на каждое обращение — без ключей; ни ключи, ни тело
     * ответа не попадают ни в одну запись журнала.
     */
    public function testEveryIssueIsAuditedWithoutKeys(): void
    {
        $client = static::createClient();
        $this->accountWithAdvertising('audit-shop', 'audit-seller-key', 'audit-perf-client', 'audit-perf-secret');

        $client->request('GET', self::URL, server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN]);

        self::assertResponseIsSuccessful();
        $handler = static::getContainer()->get('monolog.handler.in_memory');
        self::assertInstanceOf(TestHandler::class, $handler);
        self::assertTrue($handler->hasInfoThatContains('marketplace connections issued to service'));
        $audit = array_values(array_filter(
            $handler->getRecords(),
            static fn ($record): bool => 'service_audit' === $record->channel,
        ));
        self::assertCount(2, $audit, 'строка подписчика на каждое обращение и строка выдачи');
        $issued = array_values(array_filter($audit, static fn ($record): bool => 'marketplace connections issued to service' === $record->message));
        self::assertCount(1, $issued);
        self::assertGreaterThanOrEqual(1, $issued[0]->context['connections']);
        foreach ($handler->getRecords() as $record) {
            $line = $record->message.json_encode($record->context).json_encode($record->extra);
            foreach (['audit-seller-key', 'audit-perf-secret', self::TOKEN] as $secret) {
                self::assertStringNotContainsString($secret, $line);
            }
        }
    }

    /**
     * Отказ — тоже обращение: попытка с чужим токеном, без токена или
     * с неверными параметрами оставляет строку аудита, а отказ токена —
     * уровня warning, чтобы пройти порог журнала в prod.
     */
    public function testRefusedRequestsAreAuditedToo(): void
    {
        $client = static::createClient();

        $audit = [];
        foreach ([
            [self::URL, []],
            [self::URL, ['HTTP_AUTHORIZATION' => 'Bearer '.substr(self::TOKEN, 0, -1)]],
            [self::URL.'&limit=0', ['HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN]],
        ] as [$url, $server]) {
            $client->request('GET', $url, server: $server);
            // Журнал читается после каждого запроса: между запросами
            // клиент пересобирает ядро, и записи прошлого пропадают.
            $handler = static::getContainer()->get('monolog.handler.in_memory');
            self::assertInstanceOf(TestHandler::class, $handler);
            foreach ($handler->getRecords() as $record) {
                if ('service_audit' === $record->channel) {
                    $audit[] = $record;
                }
            }
        }

        self::assertSame(
            [['WARNING', 401], ['WARNING', 401], ['INFO', 422]],
            array_map(static fn ($record): array => [$record->level->getName(), $record->context['status']], $audit),
        );
        foreach ($audit as $record) {
            self::assertSame('/api/internal/marketplace-connections', $record->context['path']);
            self::assertStringNotContainsString(substr(self::TOKEN, 0, -1), (string) json_encode($record->context));
        }
    }

    /**
     * Строка с повреждённым шифротекстом не роняет выдачу остальных
     * компаний: она пропускается и пишется ошибкой без ключей.
     */
    public function testUndecryptableConnectionIsSkippedNotFailingThePage(): void
    {
        $client = static::createClient();
        $broken = MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withExternalShopId('corrupt-shop')
            ->withCredentials(base64_encode('not a ciphertext of ours at all, long enough'), 1)
            ->persistWith($this->companies(), $this->marketplaceAccounts());
        $healthy = $this->sellerOnlyAccount('healthy-shop', 'healthy-key');

        $client->request('GET', self::URL.'&limit=200', server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN]);

        self::assertResponseIsSuccessful();
        $this->assertMatchesContract((string) $client->getResponse()->getContent());
        $issued = $this->connectionsById($this->decode($client));
        self::assertArrayHasKey($healthy->id()->toRfc4122(), $issued);
        self::assertArrayNotHasKey($broken->id()->toRfc4122(), $issued);
        $handler = static::getContainer()->get('monolog.handler.in_memory');
        self::assertInstanceOf(TestHandler::class, $handler);
        self::assertTrue($handler->hasErrorThatContains('cannot be decrypted'));
    }

    public function testKeysetPagesCoverEveryConnectionOnce(): void
    {
        $client = static::createClient();
        $ids = [];
        foreach (['page-a', 'page-b', 'page-c'] as $shop) {
            $ids[] = $this->sellerOnlyAccount($shop, $shop.'-key')->id()->toRfc4122();
        }

        $seen = [];
        $url = self::URL.'&limit=1';
        for ($page = 0; $page < 50; ++$page) {
            $client->request('GET', $url, server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN]);
            self::assertResponseIsSuccessful();
            $this->assertMatchesContract((string) $client->getResponse()->getContent());
            $payload = $this->decode($client);
            self::assertIsArray($payload['connections']);
            self::assertLessThanOrEqual(1, \count($payload['connections']));
            foreach ($this->connectionsById($payload) as $id => $connection) {
                $seen[] = $id;
            }
            if (null === $payload['next_cursor']) {
                break;
            }
            self::assertIsString($payload['next_cursor']);
            $url = self::URL.'&limit=1&cursor='.urlencode($payload['next_cursor']);
        }

        self::assertSame(array_unique($seen), $seen, 'подключение не повторяется на страницах');
        $sorted = $seen;
        sort($sorted);
        self::assertSame($sorted, $seen, 'порядок — по marketplace_account_id');
        foreach ($ids as $id) {
            self::assertContains($id, $seen);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'no provider' => ['/api/internal/marketplace-connections', 'provider_invalid'];
        yield 'other provider' => ['/api/internal/marketplace-connections?provider=wildberries', 'provider_invalid'];
        yield 'limit above max' => [self::URL.'&limit=201', 'limit_too_large'];
        yield 'zero limit' => [self::URL.'&limit=0', 'limit_invalid'];
        yield 'fractional limit' => [self::URL.'&limit=1.5', 'limit_invalid'];
        yield 'garbage cursor' => [self::URL.'&cursor=not!a!cursor', 'cursor_invalid'];
        yield 'cursor of non-uuid' => [self::URL.'&cursor='.rtrim(strtr(base64_encode('42'), '+/', '-_'), '='), 'cursor_invalid'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidQueries')]
    public function testInvalidQueryIsRejected(string $url, string $code): void
    {
        $client = static::createClient();

        $client->request('GET', $url, server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($code, $this->decode($client)['code']);
    }

    private function assertMatchesContract(string $body): void
    {
        $schema = file_get_contents(self::SCHEMA);
        self::assertIsString($schema, 'schema.json контракта смонтирован в php-cli (docker-compose.yml)');

        $result = new Validator()->validate(json_decode($body, false, flags: \JSON_THROW_ON_ERROR), $schema);

        self::assertTrue(
            $result->isValid(),
            null === $result->error() ? '' : json_encode(new ErrorFormatter()->format($result->error()), \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
        );
    }

    private function sellerOnlyAccount(
        string $clientId,
        string $apiKey,
        ?\DateTimeImmutable $connectedAt = null,
        MarketplaceAccountState $state = MarketplaceAccountState::Active,
    ): MarketplaceAccount {
        $builder = MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withExternalShopId($clientId)
            ->withState($state)
            ->withPlaintextCredentials(['client_id' => $clientId, 'api_key' => $apiKey], $this->encryptor());
        if (null !== $connectedAt) {
            $builder = $builder->withConnectedAt($connectedAt);
        }

        return $builder->persistWith($this->companies(), $this->marketplaceAccounts());
    }

    private function accountWithAdvertising(string $clientId, string $apiKey, string $perfClientId, string $perfSecret): MarketplaceAccount
    {
        return MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($this->companies()))
            ->withExternalShopId($clientId)
            ->withAdvertisingConnected()
            ->withPlaintextCredentials([
                'client_id' => $clientId,
                'api_key' => $apiKey,
                ReplaceAdvertisingCredentialsAction::PerformanceClientIdKey => $perfClientId,
                ReplaceAdvertisingCredentialsAction::PerformanceClientSecretKey => $perfSecret,
            ], $this->encryptor())
            ->persistWith($this->companies(), $this->marketplaceAccounts());
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, array<string, mixed>>
     */
    private function connectionsById(array $payload): array
    {
        self::assertIsArray($payload['connections']);
        $byId = [];
        foreach ($payload['connections'] as $connection) {
            self::assertIsArray($connection);
            self::assertIsString($connection['marketplace_account_id']);
            $byId[$connection['marketplace_account_id']] = $connection;
        }

        return $byId;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(KernelBrowser $client): array
    {
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
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
}
