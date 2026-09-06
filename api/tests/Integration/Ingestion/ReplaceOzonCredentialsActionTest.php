<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\MarketplaceCredentialsEncryptor;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Application\ReplaceCredentialsResult;
use App\Ingestion\Application\ReplaceOzonCredentialsAction;
use App\Ingestion\Domain\OzonCatalogFetcher;
use App\Ingestion\Domain\OzonExpensesFetcher;
use App\Ingestion\Domain\OzonPostingsFetcher;
use App\Ingestion\Domain\OzonProductInfoFetcher;
use App\Ingestion\Domain\OzonReturnsFetcher;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonAccrualByDayClient;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonPostingFboListClient;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonProductInfoListClient;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonProductListClient;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonReturnsListClient;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Замена ключей Ozon (ADR-007): проба покрывает все четыре области
 * синхронизации, тот же приём, что у ConnectOzonAccountAction, и по той
 * же причине (боевой инцидент — ключ, прошедший только товарную область,
 * оживил бы сломанное подключение на несколько секунд и сломал бы его
 * снова на первом реальном запросе).
 *
 * HTTP-контракт (статусы, аудит-журнал, секрет вне ответа) проверяется
 * в ReplaceConnectionCredentialsControllerTest; здесь — только поведение
 * самой пробы, которое через HTTP проверять пришлось бы обходным путём.
 *
 * Обращений к настоящему Ozon нет (ADR-005).
 */
final class ReplaceOzonCredentialsActionTest extends KernelTestCase
{
    /**
     * Проба карточек сделана своим запросом, а не выведена из товарной:
     * то самое допущение «одна область прав» уже подводило на продажах
     * и финансах, повторять его здесь означало бы не выучить урок дважды.
     */
    public function testAcceptedKeyProbesProductInfoWhenSellerHasAProductAndReplacesCredentials(): void
    {
        [$company, $userId] = $this->companyWithOwner();
        $account = $this->brokenConnection($company);
        $this->stubCatalogWithProduct(200);
        $this->stubProductInfo(200);
        $this->stubPostings(200);
        $this->stubExpenses(200);
        $this->stubReturns(200);

        $result = ($this->action())($company->id()->toRfc4122(), $account->id()->toRfc4122(), 'shop-1', 'live-key', 1, $userId);

        self::assertSame(ReplaceCredentialsResult::Replaced, $result);
    }

    public function testProductInfoScopeRejectionDoesNotReplaceCredentials(): void
    {
        [$company, $userId] = $this->companyWithOwner();
        $account = $this->brokenConnection($company);
        $before = $this->ciphertext($account);
        $this->stubCatalogWithProduct(200);
        $this->stubProductInfo(403);

        $result = ($this->action())($company->id()->toRfc4122(), $account->id()->toRfc4122(), 'shop-1', 'product-info-scope-missing', 1, $userId);

        self::assertSame(ReplaceCredentialsResult::RejectedProductInfo, $result);
        // Старый ключ остался на месте — попытка заменить его провалившимся
        // ключом не должна тронуть сохранённый шифротекст.
        self::assertSame($before, $this->ciphertext($account));
        self::assertSame('broken', $this->state($account));
    }

    /**
     * Продавец без единого товара не должен упереться в невозможность
     * заменить ключ вовсе: пробовать право на карточки нечем, эндпоинт
     * отверг бы пустой список идентификаторов ошибкой параметров (400),
     * не авторизации.
     */
    public function testSellerWithoutAnyProductsSkipsTheProductInfoProbe(): void
    {
        [$company, $userId] = $this->companyWithOwner();
        $account = $this->brokenConnection($company);
        $this->stubCatalog(200);
        static::getContainer()->set(OzonProductInfoListClient::class, new class implements OzonProductInfoFetcher {
            public function fetchNames(string $clientId, string $apiKey, array $productIds): string
            {
                throw new \LogicException('Проба карточек не должна вызываться без единого товара у продавца.');
            }
        });
        $this->stubPostings(200);
        $this->stubExpenses(200);
        $this->stubReturns(200);

        $result = ($this->action())($company->id()->toRfc4122(), $account->id()->toRfc4122(), 'shop-1', 'live-key', 1, $userId);

        self::assertSame(ReplaceCredentialsResult::Replaced, $result);
    }

    public function testUnavailableOnProductInfoProbeIsNotReportedAsAWrongKey(): void
    {
        [$company, $userId] = $this->companyWithOwner();
        $account = $this->brokenConnection($company);
        $before = $this->ciphertext($account);
        $this->stubCatalogWithProduct(200);
        $this->stubProductInfo(503);

        $result = ($this->action())($company->id()->toRfc4122(), $account->id()->toRfc4122(), 'shop-1', 'live-key', 1, $userId);

        self::assertSame(ReplaceCredentialsResult::Unavailable, $result);
        self::assertSame($before, $this->ciphertext($account));
        self::assertSame('broken', $this->state($account));
    }

    public function testNonHttpClientExceptionOnProductInfoProbePropagates(): void
    {
        [$company, $userId] = $this->companyWithOwner();
        $account = $this->brokenConnection($company);
        $this->stubCatalogWithProduct(200);
        static::getContainer()->set(OzonProductInfoListClient::class, new class implements OzonProductInfoFetcher {
            public function fetchNames(string $clientId, string $apiKey, array $productIds): string
            {
                throw new \RuntimeException('неожиданный дефект нашего кода');
            }
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('неожиданный дефект нашего кода');

        ($this->action())($company->id()->toRfc4122(), $account->id()->toRfc4122(), 'shop-1', 'live-key', 1, $userId);
    }

    /**
     * Битое тело каталога — не отказ права и не недоступность площадки:
     * оно обязано пробрасываться, а не превращаться в отказ на товарах.
     */
    public function testMalformedCatalogBodyPropagatesInsteadOfBeingTreatedAsAScopeFailure(): void
    {
        [$company, $userId] = $this->companyWithOwner();
        $account = $this->brokenConnection($company);
        static::getContainer()->set(OzonProductListClient::class, new class implements OzonCatalogFetcher {
            public function fetchPage(string $clientId, string $apiKey, string $lastId, int $limit = 1000): string
            {
                $client = new MockHttpClient(new MockResponse('not-json', ['http_code' => 200]));

                return $client->request('POST', 'https://api-seller.ozon.ru/v3/product/list')->getContent();
            }
        });

        $this->expectException(\JsonException::class);

        ($this->action())($company->id()->toRfc4122(), $account->id()->toRfc4122(), 'shop-1', 'live-key', 1, $userId);
    }

    public function testSalesScopeRejectionDoesNotReplaceCredentials(): void
    {
        [$company, $userId] = $this->companyWithOwner();
        $account = $this->brokenConnection($company);
        $this->stubCatalog(200);
        $this->stubPostings(401);

        $result = ($this->action())($company->id()->toRfc4122(), $account->id()->toRfc4122(), 'shop-1', 'sales-scope-missing', 1, $userId);

        self::assertSame(ReplaceCredentialsResult::RejectedSales, $result);
    }

    public function testExpensesScopeRejectionDoesNotReplaceCredentials(): void
    {
        [$company, $userId] = $this->companyWithOwner();
        $account = $this->brokenConnection($company);
        $this->stubCatalog(200);
        $this->stubPostings(200);
        $this->stubExpenses(403);

        $result = ($this->action())($company->id()->toRfc4122(), $account->id()->toRfc4122(), 'shop-1', 'expenses-scope-missing', 1, $userId);

        self::assertSame(ReplaceCredentialsResult::RejectedExpenses, $result);
    }

    public function testReturnsScopeRejectionDoesNotReplaceCredentials(): void
    {
        [$company, $userId] = $this->companyWithOwner();
        $account = $this->brokenConnection($company);
        $this->stubCatalog(200);
        $this->stubPostings(200);
        $this->stubExpenses(200);
        $this->stubReturns(401);

        $result = ($this->action())($company->id()->toRfc4122(), $account->id()->toRfc4122(), 'shop-1', 'returns-scope-missing', 1, $userId);

        self::assertSame(ReplaceCredentialsResult::RejectedReturns, $result);
    }

    public function testAcceptedKeyOnAllScopesReplacesCredentials(): void
    {
        [$company, $userId] = $this->companyWithOwner();
        $account = $this->brokenConnection($company);
        $this->allScopesSucceed();

        $result = ($this->action())($company->id()->toRfc4122(), $account->id()->toRfc4122(), 'shop-1', 'live-key', 1, $userId);

        self::assertSame(ReplaceCredentialsResult::Replaced, $result);
    }

    public function testUnavailableOnFirstProbeGivesUnavailableNotRejected(): void
    {
        [$company, $userId] = $this->companyWithOwner();
        $account = $this->brokenConnection($company);
        $before = $this->ciphertext($account);
        // Лимит запросов на самой первой пробе — недоступность площадки,
        // не отказ ключа. Ключ не сохраняется, подключение не оживает.
        $this->stubCatalog(429);

        $result = ($this->action())($company->id()->toRfc4122(), $account->id()->toRfc4122(), 'shop-1', 'live-key', 1, $userId);

        self::assertSame(ReplaceCredentialsResult::Unavailable, $result);
        self::assertSame($before, $this->ciphertext($account));
        self::assertSame('broken', $this->state($account));
    }

    public function testUnavailableOnALaterProbeIsStillUnavailableNotRejected(): void
    {
        [$company, $userId] = $this->companyWithOwner();
        $account = $this->brokenConnection($company);
        $before = $this->ciphertext($account);
        // Разбор одинаков независимо от того, какая по счёту это проба —
        // сбой на четвёртой (возвраты) не должен вести к другому исходу.
        $this->stubCatalog(200);
        $this->stubPostings(200);
        $this->stubExpenses(200);
        $this->stubReturns(503);

        $result = ($this->action())($company->id()->toRfc4122(), $account->id()->toRfc4122(), 'shop-1', 'live-key', 1, $userId);

        self::assertSame(ReplaceCredentialsResult::Unavailable, $result);
        self::assertSame($before, $this->ciphertext($account));
        self::assertSame('broken', $this->state($account));

        // Сигнал уровня warning остаётся в журнале, но секрет в него
        // не попадает ни в каком виде.
        $handler = static::getContainer()->get('monolog.handler.in_memory');
        self::assertInstanceOf(TestHandler::class, $handler);
        self::assertTrue($handler->hasWarningThatContains('не ответил при проверке ключей замены'));
        foreach ($handler->getRecords() as $record) {
            self::assertStringNotContainsString('live-key', $record->message);
            self::assertStringNotContainsString('live-key', (string) json_encode($record->context));
        }
    }

    public function testNonHttpClientExceptionOnALaterProbeStillPropagates(): void
    {
        [$company, $userId] = $this->companyWithOwner();
        $account = $this->brokenConnection($company);
        $this->stubCatalog(200);
        static::getContainer()->set(OzonPostingFboListClient::class, new class implements OzonPostingsFetcher {
            public function fetch(string $clientId, string $apiKey, \DateTimeImmutable $since, \DateTimeImmutable $to): string
            {
                throw new \RuntimeException('неожиданный дефект нашего кода');
            }
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('неожиданный дефект нашего кода');

        ($this->action())($company->id()->toRfc4122(), $account->id()->toRfc4122(), 'shop-1', 'live-key', 1, $userId);
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

    /**
     * Каталог с одним товаром: PROBE_LIMIT = 1 у самой пробы держит
     * страницу максимум в один элемент, и этого достаточно, чтобы
     * `productIds()` вернул непустой список для пробы карточек.
     */
    private function stubCatalogWithProduct(int $status): void
    {
        $body = 200 === $status
            ? '{"result":{"items":[{"sku":220280923,"offer_id":"offer-1","product_id":111}],"last_id":"","total":1}}'
            : $this->bodyFor($status);
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

    private function stubProductInfo(int $status): void
    {
        $body = 200 === $status ? '{"items":[]}' : $this->bodyFor($status);
        static::getContainer()->set(OzonProductInfoListClient::class, new class($body, $status) implements OzonProductInfoFetcher {
            public function __construct(
                private readonly string $body,
                private readonly int $status,
            ) {
            }

            public function fetchNames(string $clientId, string $apiKey, array $productIds): string
            {
                $client = new MockHttpClient(new MockResponse($this->body, ['http_code' => $this->status]));

                return $client->request('POST', 'https://api-seller.ozon.ru/v3/product/info/list')->getContent();
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

    private function action(): ReplaceOzonCredentialsAction
    {
        $action = static::getContainer()->get(ReplaceOzonCredentialsAction::class);
        self::assertInstanceOf(ReplaceOzonCredentialsAction::class, $action);

        return $action;
    }

    private function brokenConnection(Company $company): MarketplaceAccount
    {
        /** @var MarketplaceCredentialsEncryptor $encryptor */
        $encryptor = static::getContainer()->get(MarketplaceCredentialsEncryptor::class);

        return MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($company)
            ->withState(MarketplaceAccountState::Broken)
            ->withExternalShopId('shop-1')
            ->withPlaintextCredentials(['client_id' => 'shop-1', 'api_key' => 'old-key'], $encryptor)
            ->persistWith($this->companies(), $this->marketplaceAccounts());
    }

    /** @return array{Company, string} */
    private function companyWithOwner(): array
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

        return [$company, $user->id()->toRfc4122()];
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

    private function ciphertext(MarketplaceAccount $account): string
    {
        $ciphertext = $this->connection()->fetchOne(
            'SELECT credentials_ciphertext FROM marketplace_account WHERE id = ?',
            [$account->id()->toRfc4122()],
        );
        self::assertIsString($ciphertext);

        return $ciphertext;
    }

    private function state(MarketplaceAccount $account): string
    {
        $state = $this->connection()->fetchOne(
            'SELECT state FROM marketplace_account WHERE id = ?',
            [$account->id()->toRfc4122()],
        );
        self::assertIsString($state);

        return $state;
    }

    private function connection(): Connection
    {
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
