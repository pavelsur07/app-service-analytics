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
use App\Ingestion\Domain\OzonReturnsFetcher;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonAccrualByDayClient;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonPostingFboListClient;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonProductListClient;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonReturnsListClient;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
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

    public function testUnavailableProbeIsNotTreatedAsARejectedKey(): void
    {
        [$company, $userId] = $this->companyWithOwner();
        $account = $this->brokenConnection($company);
        // Лимит запросов на пробе продаж — не «ключ не подошёл». Контракт
        // этого эндпоинта не расширяется исходом Unavailable (CLAUDE.md,
        // «Когда остановиться и спросить» — изменение уже используемого
        // фронтендом контракта), поэтому недоступность площадки остаётся
        // исключением, как и раньше.
        $this->stubCatalog(200);
        $this->stubPostings(429);

        $this->expectException(\Throwable::class);

        ($this->action())($company->id()->toRfc4122(), $account->id()->toRfc4122(), 'shop-1', 'live-key', 1, $userId);
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
}
