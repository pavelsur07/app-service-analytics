<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Domain\CompanyMemberRepository;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\MarketplaceCredentialsEncryptor;
use App\Identity\Domain\UserRepository;
use App\Ingestion\Application\Message\FetchOzonCatalogMessage;
use App\Ingestion\Application\MessageHandler\FetchOzonCatalogHandler;
use App\Ingestion\Domain\OzonCatalogFetcher;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonProductListClient;
use App\Ingestion\Infrastructure\Query\RecentlyIngestedAccountsQuery;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\UserBuilder;
use App\Tests\Support\Fake\FakeOzonCatalogFetcher;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Клиент -> парсер -> замена каталога, через реальный Postgres и реальную
 * расшифровку credentials; подменяется только HTTP (ADR-005).
 */
final class FetchOzonCatalogHandlerTest extends KernelTestCase
{
    private const string FIXTURE = __DIR__.'/../../Fixtures/Marketplace/ozon/product-list-2026-08-13.json';

    public function testCatalogOfRealCabinetIsStored(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);

        $this->fetcher($container, [$this->fixtureBody()]);
        $this->syncCatalog($container, $account);

        self::assertSame(62, $this->skuCount($container, $account));
        self::assertTrue($this->hasSku($container, $account, '220280923'));
    }

    public function testHandlingTheSameCatalogTwiceChangesNothing(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $body = $this->fixtureBody();
        $this->fetcher($container, [$body, $body]);

        $this->syncCatalog($container, $account);
        $afterFirst = $this->rows($container, $account);
        $rawAfterFirst = $this->rawDocumentCount($container, $account);

        // Идемпотентность (CLAUDE.md §4, §9): повторная синхронизация
        // не заводит ни второй строки каталога, ни второго raw-документа
        // и не меняет ни одной существующей. Сравнение по количеству
        // пропустило бы подмену содержимого строки при том же числе
        // строк — поэтому сверяются сами строки целиком.
        $this->syncCatalog($container, $account);

        self::assertSame($afterFirst, $this->rows($container, $account));
        self::assertSame($rawAfterFirst, $this->rawDocumentCount($container, $account));
        self::assertCount(62, $afterFirst);
    }

    public function testAllPagesAreFetchedBeforeTheCatalogIsReplaced(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);

        // Полная страница с непустым курсором — признак продолжения.
        // Записать после первой страницы означало бы стереть товары,
        // которые ещё не прочитаны: replaceForAccount удаляет всё,
        // чего нет в переданном списке.
        $full = $this->pageOf(range(1, 1000), 'cursor-2');
        $tail = $this->pageOf([2001, 2002], '');

        $fetcher = $this->fetcher($container, [$full, $tail]);
        $this->syncCatalog($container, $account);

        self::assertSame(['', 'cursor-2'], $fetcher->requestedCursors);
        self::assertSame(1002, $this->skuCount($container, $account));
        self::assertTrue($this->hasSku($container, $account, '2002'));
    }

    public function testVanishedProductLeavesTheCatalog(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);

        $this->fetcher($container, [$this->pageOf([111, 222], ''), $this->pageOf([111], '')]);

        $this->syncCatalog($container, $account);
        // Товар снят с продажи и из выгрузки пропал: он перестаёт быть
        // «своей карточкой», иначе оверлей отвечал бы по нему вечно.
        $this->syncCatalog($container, $account);

        self::assertTrue($this->hasSku($container, $account, '111'));
        self::assertFalse($this->hasSku($container, $account, '222'));
    }

    public function testCatalogOfAnotherCompanyIsNotTouched(): void
    {
        $container = $this->bootedContainer();
        $ours = $this->account($container);
        $theirs = $this->account($container);

        // Обязательное покрытие ADR-005: изоляция между компаниями.
        // Артикул один и тот же — товары площадки общие для всех
        // продавцов, и синхронизация одной компании не должна ни стирать,
        // ни присваивать каталог другой.
        $this->fetcher($container, [$this->pageOf([111, 999], ''), $this->pageOf([111], '')]);

        $this->syncCatalog($container, $theirs);
        $this->syncCatalog($container, $ours);

        self::assertTrue($this->hasSku($container, $theirs, '999'));
        self::assertTrue($this->hasSku($container, $theirs, '111'));
        self::assertFalse($this->hasSku($container, $ours, '999'));
    }

    public function testRepeatedCursorStopsTheSyncLoudly(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);

        // Площадка отдаёт тот же курсор — выгрузка не двигается.
        // Молча записать то, что успели прочитать, нельзя: это стёрло бы
        // остальной каталог. Крутиться вечно, держа воркер, — тоже.
        $page = $this->pageOf(range(1, 1000), 'stuck');

        $this->fetcher($container, [$page, $page]);

        $this->expectException(\RuntimeException::class);

        $this->syncCatalog($container, $account);
    }

    /**
     * Заглушка HTTP ставится в контейнер один раз на тест: повторный
     * set() по уже созданному сервису контейнер запрещает. Поэтому
     * страницы всех прогонов задаются одной очередью — она же показывает,
     * что обработчик не запросил лишнего.
     */
    public function testAuthorizationFailureBreaksTheAccountInsteadOfRetrying(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);

        // Площадка отклонила ключ (ADR-007). Повторять бессмысленно:
        // подключение переводится в broken, клиент получает письмо,
        // обработчик завершается без исключения — иначе сообщение
        // трижды ретраилось бы и осело в отказах, шумом поверх события,
        // которое уже обработано.
        $container->set(OzonProductListClient::class, new class implements OzonCatalogFetcher {
            public function fetchPage(string $clientId, string $apiKey, string $lastId, int $limit = 1000): string
            {
                $client = new MockHttpClient(new MockResponse('{"code":16}', ['http_code' => 401]));

                return $client->request('POST', 'https://api-seller.ozon.ru/v3/product/list')->getContent();
            }
        });

        $this->syncCatalog($container, $account);

        $state = $this->connection($container)->fetchOne(
            'SELECT state FROM marketplace_account WHERE id = ?',
            [$account->id()->toRfc4122()],
        );
        self::assertSame('broken', $state);
        self::assertSame(0, $this->skuCount($container, $account));
    }

    /**
     * Заглушка HTTP ставится в контейнер один раз на тест: повторный
     * set() по уже созданному сервису контейнер запрещает. Поэтому
     * страницы всех прогонов задаются одной очередью — она же показывает,
     * что обработчик не запросил лишнего.
     *
     * @param list<string> $pages
     */
    private function fetcher(ContainerInterface $container, array $pages): FakeOzonCatalogFetcher
    {
        $fetcher = new FakeOzonCatalogFetcher($pages);
        $container->set(OzonProductListClient::class, $fetcher);

        return $fetcher;
    }

    private function syncCatalog(ContainerInterface $container, MarketplaceAccount $account): void
    {
        /** @var FetchOzonCatalogHandler $handler */
        $handler = $container->get(FetchOzonCatalogHandler::class);
        ($handler)(new FetchOzonCatalogMessage(
            companyId: $account->companyId()->toRfc4122(),
            marketplaceAccountId: $account->id()->toRfc4122(),
        ));
    }

    /**
     * @param list<int> $skus
     */
    private function pageOf(array $skus, string $lastId): string
    {
        $items = array_map(
            static fn (int $sku): array => ['product_id' => $sku, 'offer_id' => 'offer-'.$sku, 'sku' => $sku],
            $skus,
        );

        return json_encode(['result' => ['items' => $items, 'total' => \count($items), 'last_id' => $lastId]], \JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(ContainerInterface $container, MarketplaceAccount $account): array
    {
        return $this->connection($container)->fetchAllAssociative(
            'SELECT marketplace_sku, first_seen_at FROM marketplace_listing WHERE company_id = ? AND marketplace_account_id = ? ORDER BY marketplace_sku',
            [$account->companyId()->toRfc4122(), $account->id()->toRfc4122()],
        );
    }

    private function rawDocumentCount(ContainerInterface $container, MarketplaceAccount $account): int
    {
        $count = $this->connection($container)->fetchOne(
            'SELECT COUNT(*) FROM marketplace_raw_document WHERE company_id = ? AND marketplace_account_id = ?',
            [$account->companyId()->toRfc4122(), $account->id()->toRfc4122()],
        );
        self::assertIsInt($count);

        return $count;
    }

    private function skuCount(ContainerInterface $container, MarketplaceAccount $account): int
    {
        $count = $this->connection($container)->fetchOne(
            'SELECT COUNT(*) FROM marketplace_listing WHERE company_id = ? AND marketplace_account_id = ?',
            [$account->companyId()->toRfc4122(), $account->id()->toRfc4122()],
        );
        self::assertIsInt($count);

        return $count;
    }

    private function hasSku(ContainerInterface $container, MarketplaceAccount $account, string $sku): bool
    {
        $value = $this->connection($container)->fetchOne(
            'SELECT first_seen_at FROM marketplace_listing WHERE company_id = ? AND marketplace_account_id = ? AND marketplace_sku = ?',
            [$account->companyId()->toRfc4122(), $account->id()->toRfc4122(), $sku],
        );

        return \is_string($value);
    }

    public function testCatalogSyncDoesNotMakeStaleSalesLookFresh(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);

        // Сторож свежести (NotifyStaleAccountsAction) читает raw-слой,
        // а каталог теперь тоже туда пишет. Без фильтра по типу отчёта
        // исправная синхронизация каталога — она идёт тем же тиком —
        // выдавала бы вставшую синхронизацию продаж за живую.
        $this->fetcher($container, [$this->fixtureBody()]);
        $this->syncCatalog($container, $account);

        $freshSalesAccounts = (new RecentlyIngestedAccountsQuery($this->connection($container)))
            ->build(new \DateTimeImmutable('-36 hours'))
            ->executeQuery()
            ->fetchAllAssociative();

        $keys = array_map(
            static function (array $row): string {
                $fresh = RecentlyIngestedAccountsQuery::mapRow($row);

                return RecentlyIngestedAccountsQuery::key($fresh->companyId, $fresh->marketplaceAccountId);
            },
            $freshSalesAccounts,
        );

        self::assertNotContains(
            RecentlyIngestedAccountsQuery::key($account->companyId()->toRfc4122(), $account->id()->toRfc4122()),
            $keys,
        );
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

        // С участником: у компании в продукте всегда есть владелец, и без
        // него отказ авторизации некому уведомить (ADR-007) — подключение
        // без адресата это ошибка данных, а не сценарий.
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

    private function fixtureBody(): string
    {
        $body = file_get_contents(self::FIXTURE);
        self::assertIsString($body);

        return $body;
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
