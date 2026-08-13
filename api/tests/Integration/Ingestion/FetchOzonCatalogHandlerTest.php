<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\MarketplaceCredentialsEncryptor;
use App\Ingestion\Application\Message\FetchOzonCatalogMessage;
use App\Ingestion\Application\MessageHandler\FetchOzonCatalogHandler;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonProductListClient;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Fake\FakeOzonCatalogFetcher;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
        $afterFirst = $this->firstSeenAt($container, $account, '220280923');

        // Идемпотентность (CLAUDE.md §4, §9): повторная синхронизация
        // не заводит вторую строку и не делает давно известный товар
        // новым — first_seen_at остаётся прежним.
        $this->syncCatalog($container, $account);

        self::assertSame(62, $this->skuCount($container, $account));
        self::assertSame($afterFirst, $this->firstSeenAt($container, $account, '220280923'));
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
        return null !== $this->firstSeenAt($container, $account, $sku);
    }

    private function firstSeenAt(ContainerInterface $container, MarketplaceAccount $account, string $sku): ?string
    {
        $value = $this->connection($container)->fetchOne(
            'SELECT first_seen_at FROM marketplace_listing WHERE company_id = ? AND marketplace_account_id = ? AND marketplace_sku = ?',
            [$account->companyId()->toRfc4122(), $account->id()->toRfc4122(), $sku],
        );

        return \is_string($value) ? $value : null;
    }

    private function account(ContainerInterface $container): MarketplaceAccount
    {
        /** @var CompanyRepository $companies */
        $companies = $container->get(CompanyRepository::class);
        /** @var MarketplaceAccountRepository $marketplaceAccounts */
        $marketplaceAccounts = $container->get(MarketplaceAccountRepository::class);
        /** @var MarketplaceCredentialsEncryptor $encryptor */
        $encryptor = $container->get(MarketplaceCredentialsEncryptor::class);

        return MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
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
