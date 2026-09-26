<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Domain\CompanyMemberRepository;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\MarketplaceCredentialsEncryptor;
use App\Identity\Domain\UserRepository;
use App\Ingestion\Application\Message\FetchOzonStocksMessage;
use App\Ingestion\Application\MessageHandler\FetchOzonStocksHandler;
use App\Ingestion\Domain\MarketplaceListingRepository;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonAnalyticsStocksClient;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\MarketplaceListingBuilder;
use App\Tests\Support\Builder\UserBuilder;
use App\Tests\Support\Fake\FakeOzonStockFetcher;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Прогон снимка остатков (ADR-034): пачки SKU каталога, полнота снимка,
 * отказ посреди прогона и отказ авторизации.
 */
final class FetchOzonStocksHandlerTest extends KernelTestCase
{
    public function testFullRunRequestsCatalogInBatchesAndWritesTheDay(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $skus = array_map(static fn (int $i): string => (string) (1000 + $i), range(1, 150));
        $this->catalog($container, $account, $skus);
        $fetcher = $this->fetcher($container, [
            $this->body(\array_slice($skus, 0, 100)),
            $this->body(\array_slice($skus, 100)),
        ]);

        $this->snapshot($container, $account);

        // 150 SKU — две пачки: 100 и 50.
        self::assertSame([100, 50], array_map('count', $fetcher->requestedBatches));
        self::assertSame(150, $this->factCount($container, $account));
        $mark = $this->mark($container, $account);
        self::assertNotNull($mark['started_at']);
        self::assertCount(150, $this->json($mark['requested_skus']));
        self::assertCount(2, $this->json($mark['raw_document_ids']));
        self::assertSame(150, $mark['row_count']);
    }

    public function testFailureMidRunWritesNothing(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $skus = array_map(static fn (int $i): string => (string) (2000 + $i), range(1, 150));
        $this->catalog($container, $account, $skus);
        $this->fetcher($container, [
            $this->body(\array_slice($skus, 0, 100)),
            new \RuntimeException('Ozon 500'),
        ]);

        try {
            $this->snapshot($container, $account);
            self::fail('Отказ посреди прогона обязан быть громким — сообщение уходит в повтор.');
        } catch (\RuntimeException $expected) {
            self::assertSame('Ozon 500', $expected->getMessage());
        }

        // Половина снимка выдала бы «ноль» по второй половине каталога.
        self::assertSame(0, $this->factCount($container, $account));
        self::assertFalse($this->markExists($container, $account));
    }

    public function testAuthorizationFailureBreaksTheAccountAndWritesNothing(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $this->catalog($container, $account, ['3001']);
        $this->fetcher($container, [$this->unauthorized()]);

        $this->snapshot($container, $account);

        $state = $this->connection($container)->fetchOne('SELECT state FROM marketplace_account WHERE id = ?', [$account->id()->toRfc4122()]);
        self::assertSame('broken', $state);
        self::assertSame(0, $this->factCount($container, $account));
        self::assertFalse($this->markExists($container, $account));
    }

    public function testEmptyCatalogRequestsNothing(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $fetcher = $this->fetcher($container, []);

        $this->snapshot($container, $account);

        self::assertSame([], $fetcher->requestedBatches);
        self::assertFalse($this->markExists($container, $account));
    }

    /**
     * @param list<string> $skus
     */
    private function body(array $skus): string
    {
        $items = array_map(static fn (string $sku): array => [
            'sku' => (int) $sku,
            'warehouse_id' => 1020000115166000,
            'warehouse_name' => 'ЖУКОВСКИЙ_РФЦ',
            'cluster_id' => 154,
            'cluster_name' => 'Москва, МО и Дальние регионы',
            'available_stock_count' => 1,
            'transit_stock_count' => 0,
            'requested_stock_count' => 0,
            'return_from_customer_stock_count' => 0,
            'return_to_seller_stock_count' => 0,
            'stock_defect_stock_count' => 0,
            'transit_defect_stock_count' => 0,
            'valid_stock_count' => 0,
            'waiting_docs_stock_count' => 0,
            'expiring_stock_count' => 0,
            'excess_stock_count' => 0,
            'other_stock_count' => 0,
            'waiting_docs_to_export_stock_count' => 0,
            'stock_not_being_sold' => 0,
            'ads_cluster' => 0.5,
            'idc_cluster' => 2,
            'turnover_grade_cluster' => 'DEFICIT',
        ], $skus);

        return json_encode(['items' => $items], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    private function unauthorized(): \Throwable
    {
        try {
            (new MockHttpClient(new MockResponse('{"code":16}', ['http_code' => 401])))
                ->request('POST', 'https://api-seller.ozon.ru/v1/analytics/stocks')
                ->getContent();
        } catch (\Throwable $failure) {
            return $failure;
        }

        throw new \LogicException('MockResponse 401 must throw.');
    }

    /**
     * @param list<string|\Throwable> $responses
     */
    private function fetcher(ContainerInterface $container, array $responses): FakeOzonStockFetcher
    {
        $fetcher = new FakeOzonStockFetcher($responses);
        $container->set(OzonAnalyticsStocksClient::class, $fetcher);

        return $fetcher;
    }

    /**
     * @param list<string> $skus
     */
    private function catalog(ContainerInterface $container, MarketplaceAccount $account, array $skus): void
    {
        /** @var MarketplaceListingRepository $listings */
        $listings = $container->get(MarketplaceListingRepository::class);
        $listings->replaceForAccount(
            $account->companyId()->toRfc4122(),
            $account->id(),
            array_map(
                static fn (string $sku) => MarketplaceListingBuilder::aMarketplaceListing()
                    ->withCompanyId($account->companyId())
                    ->withMarketplaceAccountId($account->id())
                    ->withMarketplaceSku($sku)
                    ->build(),
                $skus,
            ),
        );
    }

    private function snapshot(ContainerInterface $container, MarketplaceAccount $account): void
    {
        /** @var FetchOzonStocksHandler $handler */
        $handler = $container->get(FetchOzonStocksHandler::class);
        ($handler)(new FetchOzonStocksMessage($account->companyId()->toRfc4122(), $account->id()->toRfc4122()));
    }

    private function factCount(ContainerInterface $container, MarketplaceAccount $account): int
    {
        $count = $this->connection($container)->fetchOne(
            'SELECT COUNT(*) FROM stock_snapshot_fact WHERE company_id = ? AND marketplace_account_id = ?',
            [$account->companyId()->toRfc4122(), $account->id()->toRfc4122()],
        );
        self::assertIsInt($count);

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function mark(ContainerInterface $container, MarketplaceAccount $account): array
    {
        $mark = $this->connection($container)->fetchAssociative(
            'SELECT * FROM stock_snapshot_run WHERE company_id = ? AND marketplace_account_id = ?',
            [$account->companyId()->toRfc4122(), $account->id()->toRfc4122()],
        );
        self::assertIsArray($mark);

        return $mark;
    }

    private function markExists(ContainerInterface $container, MarketplaceAccount $account): bool
    {
        return false !== $this->connection($container)->fetchOne(
            'SELECT 1 FROM stock_snapshot_run WHERE company_id = ? AND marketplace_account_id = ?',
            [$account->companyId()->toRfc4122(), $account->id()->toRfc4122()],
        );
    }

    /**
     * @return list<mixed>
     */
    private function json(mixed $value): array
    {
        self::assertIsString($value);
        $decoded = json_decode($value, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return array_values($decoded);
    }

    private function account(ContainerInterface $container): MarketplaceAccount
    {
        /** @var CompanyRepository $companies */
        $companies = $container->get(CompanyRepository::class);
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        /** @var CompanyMemberRepository $members */
        $members = $container->get(CompanyMemberRepository::class);
        /** @var MarketplaceAccountRepository $marketplaceAccounts */
        $marketplaceAccounts = $container->get(MarketplaceAccountRepository::class);
        /** @var MarketplaceCredentialsEncryptor $encryptor */
        $encryptor = $container->get(MarketplaceCredentialsEncryptor::class);

        // С владельцем: отказ авторизации уведомляет его (ADR-007).
        $company = CompanyBuilder::aCompany()->persistWith($companies);
        CompanyMemberBuilder::aCompanyMember()
            ->withCompany($company)
            ->withUser(UserBuilder::aUser()->withEmail('owner-'.bin2hex(random_bytes(4)).'@example.test')->persistWith($users))
            ->persistWith($companies, $users, $members);

        return MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($company)
            ->withExternalShopId('shop-'.bin2hex(random_bytes(4)))
            ->withPlaintextCredentials(['client_id' => 'shop-1', 'api_key' => 'key-1'], $encryptor)
            ->persistWith($companies, $marketplaceAccounts);
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
