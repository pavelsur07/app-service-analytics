<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Domain\CompanyMemberRepository;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\MarketplaceCredentialsEncryptor;
use App\Identity\Domain\UserRepository;
use App\Ingestion\Application\Message\FetchOzonReturnsMessage;
use App\Ingestion\Application\MessageHandler\FetchOzonReturnsHandler;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonReturnsListClient;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\UserBuilder;
use App\Tests\Support\Fake\ExpiringLockStore;
use App\Tests\Support\Fake\FakeOzonReturnsFetcher;
use App\Tests\Support\Fake\LeaseProbeOzonReturnsFetcher;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

final class FetchOzonReturnsHandlerTest extends KernelTestCase
{
    private const string FIXTURE = __DIR__.'/../../Fixtures/Marketplace/ozon/ozon-buyout-returns.json';

    public function testReadsAllPagesPersistsEachRawPageAndUpsertsFacts(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $fetcher = new FakeOzonReturnsFetcher([
            $this->fixturePage(0, 3, true),
            $this->fixturePage(3, 3, false),
        ]);
        $container->set(OzonReturnsListClient::class, $fetcher);

        $this->sync($container, $account);

        self::assertSame([0, 900003], array_column($fetcher->requests, 'lastId'));
        self::assertSame('2026-07-31T21:00:00+00:00', $fetcher->requests[0]['from']->format(\DateTimeInterface::ATOM));
        self::assertSame('2026-08-03T20:59:59+00:00', $fetcher->requests[0]['to']->format(\DateTimeInterface::ATOM));
        self::assertSame(2, $this->rawCount($container, $account));
        self::assertSame(6, $this->returnCount($container, $account));
        self::assertEquals(0, $this->connection($container)->fetchOne(
            'SELECT COUNT(*) FROM marketplace_return_fact f WHERE f.company_id = ? AND NOT EXISTS (SELECT 1 FROM marketplace_raw_document d WHERE d.id = f.raw_document_id)',
            [$account->companyId()->toRfc4122()],
        ));
    }

    public function testRepeatedWindowDoesNotDuplicateRawOrFacts(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $body = $this->fixturePage(0, 6, false);
        $container->set(OzonReturnsListClient::class, new FakeOzonReturnsFetcher([$body, $body]));

        $this->sync($container, $account);
        $rows = $this->returnRows($container, $account);
        $this->sync($container, $account);

        self::assertSame(1, $this->rawCount($container, $account));
        self::assertSame($rows, $this->returnRows($container, $account));
    }

    public function testAuthorizationFailureMarksAccountBrokenWithoutWritingFacts(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $container->set(OzonReturnsListClient::class, new FakeOzonReturnsFetcher([$this->authorizationFailure()]));

        $this->sync($container, $account);

        self::assertSame('broken', $this->connection($container)->fetchOne(
            'SELECT state FROM marketplace_account WHERE id = ?',
            [$account->id()->toRfc4122()],
        ));
        self::assertSame(0, $this->rawCount($container, $account));
        self::assertSame(0, $this->returnCount($container, $account));
    }

    public function testAccountLockRetriesOverlappingWindowInsteadOfLosingIt(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $fetcher = new FakeOzonReturnsFetcher([$this->fixturePage(0, 6, false)]);
        $container->set(OzonReturnsListClient::class, $fetcher);
        /** @var LockFactory $lockFactory */
        $lockFactory = $container->get(LockFactory::class);
        $lock = $lockFactory->createLock('ozon-returns-'.$account->id()->toRfc4122(), 900);
        self::assertTrue($lock->acquire());

        try {
            $this->expectException(RecoverableMessageHandlingException::class);
            $this->sync($container, $account);
        } finally {
            $lock->release();
        }
    }

    public function testRenewsTheAccountLeaseBetweenPages(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $resource = 'ozon-returns-'.$account->id()->toRfc4122();
        $store = new ExpiringLockStore();
        $locks = new LockFactory($store);
        $container->set(LockFactory::class, $locks);
        $fetcher = new LeaseProbeOzonReturnsFetcher(
            $store,
            $locks,
            $resource,
            [
                $this->fixturePage(0, 3, true),
                $this->fixturePage(3, 3, false),
            ],
        );
        $container->set(OzonReturnsListClient::class, $fetcher);

        $this->sync($container, $account);

        self::assertFalse($fetcher->overlapAcquired);
    }

    public function testOneHundredPagesWithHasNextFailsAsIncomplete(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $pages = [];
        for ($id = 1; $id <= 100; ++$id) {
            $pages[] = json_encode([
                'returns' => [$this->returnRow($id)],
                'has_next' => true,
            ], \JSON_THROW_ON_ERROR);
        }
        $fetcher = new FakeOzonReturnsFetcher($pages);
        $container->set(OzonReturnsListClient::class, $fetcher);

        try {
            $this->sync($container, $account);
            self::fail('Incomplete pagination must fail.');
        } catch (\RuntimeException $failure) {
            self::assertStringContainsString('100 страниц', $failure->getMessage());
        }

        self::assertSame(100, $this->rawCount($container, $account));
        self::assertSame(0, $this->returnCount($container, $account));
    }

    public function testPublishingPagesRollsBackIfALaterPageCannotBeWritten(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $invalid = $this->returnRow(2);
        $invalid['order_number'] = str_repeat('X', 65);
        $container->set(OzonReturnsListClient::class, new FakeOzonReturnsFetcher([
            json_encode(['returns' => [$this->returnRow(1)], 'has_next' => true], \JSON_THROW_ON_ERROR),
            json_encode(['returns' => [$invalid], 'has_next' => false], \JSON_THROW_ON_ERROR),
        ]));

        try {
            $this->sync($container, $account);
            self::fail('Invalid later page must fail publication.');
        } catch (\Throwable) {
        }

        self::assertSame(2, $this->rawCount($container, $account));
        self::assertSame(0, $this->returnCount($container, $account));
    }

    private function sync(ContainerInterface $container, MarketplaceAccount $account): void
    {
        /** @var FetchOzonReturnsHandler $handler */
        $handler = $container->get(FetchOzonReturnsHandler::class);
        ($handler)(new FetchOzonReturnsMessage(
            companyId: $account->companyId()->toRfc4122(),
            marketplaceAccountId: $account->id()->toRfc4122(),
            from: '2026-08-01',
            to: '2026-08-03',
        ));
    }

    private function fixturePage(int $offset, int $length, bool $hasNext): string
    {
        $body = file_get_contents(self::FIXTURE);
        self::assertIsString($body);
        $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['returns']);

        return json_encode([
            'returns' => \array_slice($decoded['returns'], $offset, $length),
            'has_next' => $hasNext,
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function returnRow(int $id): array
    {
        return [
            'id' => $id,
            'order_number' => 'LIMIT-'.$id,
            'type' => 'Cancellation',
            'return_reason_name' => 'Покупатель не забрал заказ',
            'posting_number' => 'LIMIT-'.$id.'-1',
            'source_id' => 10_000 + $id,
            'product' => ['sku' => 20_000 + $id, 'quantity' => 1],
            'visual' => [
                'status' => ['id' => 34, 'sys_name' => 'ReturnedToOzon'],
                'change_moment' => '2026-08-02T10:00:00Z',
            ],
        ];
    }

    private function authorizationFailure(): \Throwable
    {
        try {
            (new MockHttpClient(new MockResponse('{"code":16}', ['http_code' => 401])))
                ->request('POST', 'https://api-seller.ozon.ru/v1/returns/list')
                ->getContent();
        } catch (\Throwable $failure) {
            return $failure;
        }

        throw new \LogicException('Mock HTTP 401 did not throw.');
    }

    private function account(ContainerInterface $container): MarketplaceAccount
    {
        /** @var CompanyRepository $companies */
        $companies = $container->get(CompanyRepository::class);
        /** @var MarketplaceAccountRepository $accounts */
        $accounts = $container->get(MarketplaceAccountRepository::class);
        /** @var MarketplaceCredentialsEncryptor $encryptor */
        $encryptor = $container->get(MarketplaceCredentialsEncryptor::class);
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        /** @var CompanyMemberRepository $members */
        $members = $container->get(CompanyMemberRepository::class);
        $company = CompanyBuilder::aCompany()->persistWith($companies);
        CompanyMemberBuilder::aCompanyMember()
            ->withCompany($company)
            ->withUser(UserBuilder::aUser()->withEmail('returns-'.bin2hex(random_bytes(4)).'@example.test')->persistWith($users))
            ->persistWith($companies, $users, $members);

        return MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($company)
            ->withExternalShopId('returns-'.bin2hex(random_bytes(4)))
            ->withPlaintextCredentials(['client_id' => 'seller', 'api_key' => 'key'], $encryptor)
            ->persistWith($companies, $accounts);
    }

    private function rawCount(ContainerInterface $container, MarketplaceAccount $account): int
    {
        $count = $this->connection($container)->fetchOne(
            'SELECT COUNT(*) FROM marketplace_raw_document WHERE company_id = ? AND marketplace_account_id = ? AND report_type = ?',
            [$account->companyId()->toRfc4122(), $account->id()->toRfc4122(), MarketplaceReportType::OzonReturnsList],
        );
        self::assertIsInt($count);

        return $count;
    }

    private function returnCount(ContainerInterface $container, MarketplaceAccount $account): int
    {
        $count = $this->connection($container)->fetchOne(
            'SELECT COUNT(*) FROM marketplace_return_fact WHERE company_id = ? AND marketplace_account_id = ?',
            [$account->companyId()->toRfc4122(), $account->id()->toRfc4122()],
        );
        self::assertIsInt($count);

        return $count;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function returnRows(ContainerInterface $container, MarketplaceAccount $account): array
    {
        return $this->connection($container)->fetchAllAssociative(
            'SELECT source_row_id, row_hash, first_loaded_at, last_updated_at FROM marketplace_return_fact WHERE company_id = ? AND marketplace_account_id = ? ORDER BY source_row_id',
            [$account->companyId()->toRfc4122(), $account->id()->toRfc4122()],
        );
    }

    private function connection(ContainerInterface $container): Connection
    {
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);

        return $connection;
    }

    private function bootedContainer(): ContainerInterface
    {
        self::bootKernel();

        return self::getContainer();
    }
}
