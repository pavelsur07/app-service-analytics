<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Domain\CompanyMemberRepository;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\MarketplaceCredentialsEncryptor;
use App\Identity\Domain\UserRepository;
use App\Ingestion\Application\Message\FetchOzonAdCampaignStatsMessage;
use App\Ingestion\Application\MessageHandler\FetchOzonAdCampaignStatsHandler;
use App\Ingestion\Application\OzonAdvertisingWindows;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Domain\OzonAdvertisingFetcher;
use App\Ingestion\Infrastructure\Connector\OzonPerformance\OzonPerformanceCampaignClient;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Connection;
use Monolog\Handler\TestHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

/**
 * Реклама в raw (ADR-026 п. 3): ответы Performance API сохраняются как
 * есть, через реальный Postgres, реальное хранилище сырья и реальную
 * расшифровку ключа; подменяется только клиент площадки (ADR-005),
 * ответы — снятые фикстуры кабинета.
 */
final class FetchOzonAdvertisingHandlersTest extends KernelTestCase
{
    private const string FIXTURES = __DIR__.'/../../Fixtures/Marketplace/ozon/performance/';

    public function testExpenseAndDailyAreStoredAsReceived(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $this->fetcher($container);

        $this->syncStats($container, $account);

        self::assertSame(
            [$this->fixture('statistics-expense-2026-08-25.json')],
            $this->rawBodies($container, $account, MarketplaceReportType::OzonAdExpense),
        );
        self::assertSame(
            [$this->fixture('statistics-daily-2026-08-25.json')],
            $this->rawBodies($container, $account, MarketplaceReportType::OzonAdDaily),
        );
        self::assertSame(['2026-08-25'], $this->rawPeriods($container, $account, MarketplaceReportType::OzonAdExpense));
    }

    public function testFreshChunkAlsoStoresSkuForYesterdayAndToday(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $fetcher = $this->fetcher($container);
        $chunk = OzonAdvertisingWindows::lastDays(OzonAdvertisingWindows::today(new \DateTimeImmutable()), 30)[0];

        $this->syncStats($container, $account, $chunk['from'], $chunk['to']);

        $today = OzonAdvertisingWindows::today(new \DateTimeImmutable());
        $expectedDays = [$today->format('Y-m-d'), $today->modify('-1 day')->format('Y-m-d')];
        self::assertSame($expectedDays, array_values(array_unique(array_column($fetcher->skuRequests, 'day'))));

        // В снятом списке 94 кампании; products/sku получает только
        // неархивные типа SKU — у других списка товаров нет, — и не больше
        // десяти за запрос.
        $expectedIds = $this->activeSkuCampaignIds();
        $requested = [];
        foreach ($fetcher->skuRequests as $request) {
            self::assertLessThanOrEqual(10, \count($request['campaigns']));
            if ($request['day'] === $expectedDays[0]) {
                $requested = [...$requested, ...$request['campaigns']];
            }
        }
        self::assertSame($expectedIds, $requested);

        // Ответы разных пачек одного дня совпадают (заглушка отдаёт одну
        // фикстуру) и дедуплицируются: по документу на день.
        self::assertSame(
            array_reverse($expectedDays),
            $this->rawPeriods($container, $account, MarketplaceReportType::OzonAdSkuDay),
        );
        // Список, по которому выбраны кампании, сохранён до разбора
        // с днём снимка из сообщения (ADR-006).
        self::assertSame([$chunk['to']], $this->rawPeriods($container, $account, MarketplaceReportType::OzonAdCampaigns));
    }

    public function testOldChunkDoesNotAskForSku(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $fetcher = $this->fetcher($container);

        // products/sku отдаёт только сегодня и вчера: кусок истории его
        // не вызывает вовсе.
        $this->syncStats($container, $account);

        self::assertSame([], $fetcher->skuRequests);
    }

    public function testRepeatedChunkDoesNotDuplicateRaw(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $this->fetcher($container);

        // Идемпотентность (CLAUDE.md §4): тот же ответ за тот же период —
        // тот же raw-документ, а не второй.
        $this->syncStats($container, $account);
        $this->syncStats($container, $account);

        self::assertCount(1, $this->rawBodies($container, $account, MarketplaceReportType::OzonAdExpense));
        self::assertCount(1, $this->rawBodies($container, $account, MarketplaceReportType::OzonAdDaily));
    }

    public function testDelayedHeadChunkStillStoresTheCampaignList(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $fetcher = $this->fetcher($container);
        $today = OzonAdvertisingWindows::today(new \DateTimeImmutable());

        // Головной кусок тика обработан на два дня позже: вчера и сегодня
        // в нём уже нет, но список кампаний — единственный за тик — он
        // сохранить обязан.
        $this->syncStats($container, $account, $today->modify('-31 days')->format('Y-m-d'), $today->modify('-2 days')->format('Y-m-d'));

        self::assertSame(
            [$this->fixture('campaign-list.json')],
            $this->rawBodies($container, $account, MarketplaceReportType::OzonAdCampaigns),
        );
        self::assertSame([], $fetcher->skuRequests);
    }

    public function testOlderChunkDoesNotAskForTheCampaignList(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $this->fetcher($container);
        $chunks = OzonAdvertisingWindows::lastDays(OzonAdvertisingWindows::today(new \DateTimeImmutable()), 45);

        // Второй кусок тика списка не запрашивает: один запрос метода
        // на тик, иначе площадка отвечает 429.
        $this->syncStats($container, $account, $chunks[1]['from'], $chunks[1]['to']);

        self::assertSame([], $this->rawBodies($container, $account, MarketplaceReportType::OzonAdCampaigns));
    }

    public function testRawOfOneCompanyIsNotStoredUnderAnother(): void
    {
        $container = $this->bootedContainer();
        $ours = $this->account($container);
        $theirs = $this->account($container);
        $this->fetcher($container);

        // Обязательное покрытие ADR-005: одинаковый ответ двух кабинетов
        // не склеивается в один документ — компания входит в ключ raw.
        $this->syncStats($container, $ours);
        $this->syncStats($container, $theirs);

        self::assertCount(1, $this->rawBodies($container, $ours, MarketplaceReportType::OzonAdExpense));
        self::assertCount(1, $this->rawBodies($container, $theirs, MarketplaceReportType::OzonAdExpense));
    }

    public function testRejectedKeyBreaksOnlyAdvertisingAndNotifiesOnce(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $this->fetcher($container, tokenStatus: 401);

        $this->syncStats($container, $account);
        // Второй отказ — уже по сломанной рекламе: цели нет, письма нет.
        $this->syncStats($container, $account);

        // ADR-026 п. 1: сломан рекламный ключ, а не подключение —
        // продажи и расходы грузятся дальше.
        self::assertSame(['state' => 'active', 'advertising_state' => 'broken'], $this->states($container, $account));
        self::assertSame(1, $this->warningsContaining($container, 'Письмо о сломанном рекламном ключе отправлено'));
        self::assertSame([], $this->rawBodies($container, $account, MarketplaceReportType::OzonAdExpense));
    }

    public function testInactiveAdvertisingLoadsNothing(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container, advertising: false);
        $fetcher = $this->fetcher($container);

        $this->syncStats($container, $account);

        self::assertSame(0, $fetcher->calls);
        self::assertSame([], $this->rawBodies($container, $account, MarketplaceReportType::OzonAdExpense));
    }

    public function testChunkLongerThanThirtyDaysIsRefused(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $this->fetcher($container);

        $this->expectException(\InvalidArgumentException::class);
        $this->syncStats($container, $account, '2026-08-25', '2026-09-24');
    }

    /**
     * @return list<string>
     */
    private function activeSkuCampaignIds(): array
    {
        $list = json_decode($this->fixture('campaign-list.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($list);
        self::assertIsArray($list['list']);
        $ids = [];
        foreach ($list['list'] as $campaign) {
            self::assertIsArray($campaign);
            if ('CAMPAIGN_STATE_ARCHIVED' !== $campaign['state'] && 'SKU' === $campaign['advObjectType']) {
                self::assertIsString($campaign['id']);
                $ids[] = $campaign['id'];
            }
        }
        self::assertNotSame([], $ids);

        return $ids;
    }

    private function syncStats(ContainerInterface $container, MarketplaceAccount $account, string $from = '2026-08-25', string $to = '2026-09-23'): void
    {
        $handler = $container->get(FetchOzonAdCampaignStatsHandler::class);
        \assert($handler instanceof FetchOzonAdCampaignStatsHandler);
        $handler(new FetchOzonAdCampaignStatsMessage($account->companyId()->toRfc4122(), $account->id()->toRfc4122(), $from, $to));
    }

    /**
     * @return OzonAdvertisingFetcher&object{calls: int, skuRequests: list<array{day: string, campaigns: list<string>}>}
     */
    private function fetcher(ContainerInterface $container, int $tokenStatus = 200, ?\Closure $beforeRejection = null): OzonAdvertisingFetcher
    {
        $fetcher = new class($tokenStatus, $this->fixture('campaign-list.json'), $this->fixture('statistics-expense-2026-08-25.json'), $this->fixture('statistics-daily-2026-08-25.json'), $beforeRejection, $this->fixture('statistics-products-sku-2026-09-23.json')) implements OzonAdvertisingFetcher {
            public int $calls = 0;

            public function __construct(
                private readonly int $tokenStatus,
                private readonly string $campaigns,
                private readonly string $expense,
                private readonly string $daily,
                private readonly ?\Closure $beforeRejection,
                private readonly string $sku,
            ) {
            }

            public function token(string $clientId, string $clientSecret): string
            {
                ++$this->calls;
                if (200 !== $this->tokenStatus) {
                    if (null !== $this->beforeRejection) {
                        ($this->beforeRejection)();
                    }
                    // Настоящее исключение symfony/http-client: распознавание
                    // отказа авторизации смотрит на код ответа внутри него.
                    $client = new MockHttpClient(new MockResponse('{"error":"invalid_client"}', ['http_code' => $this->tokenStatus]));
                    $client->request('POST', 'https://api-performance.ozon.ru/api/client/token')->getContent();
                }

                return 'jwt';
            }

            public function campaigns(string $token): string
            {
                return $this->campaigns;
            }

            public function campaignProducts(string $token, string $campaignId): string
            {
                throw new \LogicException('Загрузка рекламы товары кампаний не запрашивает.');
            }

            public function expense(string $token, \DateTimeImmutable $from, \DateTimeImmutable $to): string
            {
                return $this->expense;
            }

            public function daily(string $token, \DateTimeImmutable $from, \DateTimeImmutable $to): string
            {
                return $this->daily;
            }

            /** @var list<array{day: string, campaigns: list<string>}> */
            public array $skuRequests = [];

            public function productsSku(string $token, array $campaignIds, \DateTimeImmutable $day): string
            {
                $this->skuRequests[] = ['day' => $day->format('Y-m-d'), 'campaigns' => $campaignIds];

                return $this->sku;
            }
        };
        $container->set(OzonPerformanceCampaignClient::class, $fetcher);

        return $fetcher;
    }

    /**
     * @return list<string>
     */
    private function rawBodies(ContainerInterface $container, MarketplaceAccount $account, string $reportType): array
    {
        $repository = MarketplaceRawDocumentBuilder::repository($container);
        $bodies = [];
        foreach ($this->rawIds($container, $account, $reportType) as $id) {
            $bodies[] = $repository->body($account->companyId()->toRfc4122(), $account->id(), $id);
        }

        return $bodies;
    }

    /**
     * @return list<Uuid>
     */
    private function rawIds(ContainerInterface $container, MarketplaceAccount $account, string $reportType): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection($container)->fetchFirstColumn(
            'SELECT id FROM marketplace_raw_document WHERE company_id = ? AND marketplace_account_id = ? AND report_type = ? ORDER BY received_at',
            [$account->companyId()->toRfc4122(), $account->id()->toRfc4122(), $reportType],
        );

        return array_map(static fn (string $id): Uuid => Uuid::fromString($id), $ids);
    }

    /**
     * @return list<string>
     */
    private function rawPeriods(ContainerInterface $container, MarketplaceAccount $account, string $reportType): array
    {
        /** @var list<string> $periods */
        $periods = $this->connection($container)->fetchFirstColumn(
            'SELECT period FROM marketplace_raw_document WHERE company_id = ? AND marketplace_account_id = ? AND report_type = ? ORDER BY period',
            [$account->companyId()->toRfc4122(), $account->id()->toRfc4122(), $reportType],
        );

        return $periods;
    }

    /**
     * @return array<string, mixed>
     */
    private function states(ContainerInterface $container, MarketplaceAccount $account): array
    {
        $row = $this->connection($container)->fetchAssociative(
            'SELECT state, advertising_state FROM marketplace_account WHERE company_id = ? AND id = ?',
            [$account->companyId()->toRfc4122(), $account->id()->toRfc4122()],
        );
        self::assertIsArray($row);

        return $row;
    }

    private function warningsContaining(ContainerInterface $container, string $text): int
    {
        $handler = $container->get('monolog.handler.in_memory');
        self::assertInstanceOf(TestHandler::class, $handler);

        return \count(array_filter(
            $handler->getRecords(),
            static fn ($record): bool => str_contains($record->message, $text),
        ));
    }

    private function account(ContainerInterface $container, bool $advertising = true): MarketplaceAccount
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

        // С участником: отказ рекламного ключа порождает письмо клиенту.
        $company = CompanyBuilder::aCompany()->persistWith($companies);
        CompanyMemberBuilder::aCompanyMember()
            ->withCompany($company)
            ->withUser(UserBuilder::aUser()->withEmail('owner-'.bin2hex(random_bytes(4)).'@example.test')->persistWith($users))
            ->persistWith($companies, $users, $members);

        $builder = MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($company)
            ->withExternalShopId('shop-'.bin2hex(random_bytes(4)))
            ->withPlaintextCredentials([
                'client_id' => 'shop-1',
                'api_key' => 'key-1',
                'performance_client_id' => '1-1@advertising.performance.ozon.ru',
                'performance_client_secret' => 'ad-secret',
            ], $encryptor);
        if ($advertising) {
            $builder = $builder->withAdvertisingConnected();
        }

        return $builder->persistWith($companies, $accounts);
    }

    private function fixture(string $name): string
    {
        $body = file_get_contents(self::FIXTURES.$name);
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
