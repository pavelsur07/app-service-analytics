<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Domain\CompanyMemberRepository;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\MarketplaceCredentialsEncryptor;
use App\Identity\Domain\UserRepository;
use App\Ingestion\Application\Message\CheckOzonAdSkuReportMessage;
use App\Ingestion\Application\Message\FetchOzonAdCampaignStatsMessage;
use App\Ingestion\Application\Message\OrderOzonAdSkuReportMessage;
use App\Ingestion\Application\MessageHandler\CheckOzonAdSkuReportHandler;
use App\Ingestion\Application\MessageHandler\FetchOzonAdCampaignStatsHandler;
use App\Ingestion\Application\MessageHandler\OrderOzonAdSkuReportHandler;
use App\Ingestion\Application\OzonAdvertisingWindows;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Domain\OzonAdReportKind;
use App\Ingestion\Infrastructure\Connector\OzonPerformance\OzonPerformanceCampaignClient;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Builder\UserBuilder;
use App\Tests\Support\Fake\FakeOzonAdvertisingFetcher;
use Doctrine\DBAL\Connection;
use Monolog\Handler\TestHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
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

    public function testDailyChunkOrdersSkuReportsWithoutYesterdayAndToday(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $fetcher = $this->fetcher($container);
        $today = OzonAdvertisingWindows::today(new \DateTimeImmutable());
        $chunk = OzonAdvertisingWindows::lastDays($today, 30)[0];

        $this->syncStats($container, $account, $chunk['from'], $chunk['to'], withReports: true);

        // Вчера и сегодня отдаёт products/sku; отчёт — за остальные дни
        // куска (ADR-026 п. 4), по всем неархивным кампаниям типа SKU,
        // не больше десяти в заказе.
        $all = $this->sent($container, OrderOzonAdSkuReportMessage::class);
        $orders = array_values(array_filter($all, static fn (OrderOzonAdSkuReportMessage $order): bool => OzonAdReportKind::Sku === OzonAdReportKind::of($order->kind)));
        self::assertNotSame([], $orders);
        $campaigns = [];
        foreach ($orders as $order) {
            self::assertSame($chunk['from'], $order->from);
            self::assertSame($today->modify('-2 days')->format('Y-m-d'), $order->to);
            self::assertLessThanOrEqual(10, \count($order->campaignIds));
            $campaigns = [...$campaigns, ...$order->campaignIds];
        }
        self::assertSame($this->activeSkuCampaignIds(), $campaigns);

        // Заказы «Оплаты за заказ» — один отчёт по всей организации
        // за весь кусок, без кампаний: products/sku для них нет.
        $cpo = array_values(array_filter($all, static fn (OrderOzonAdSkuReportMessage $order): bool => OzonAdReportKind::CpoOrders === $order->kind));
        self::assertCount(1, $cpo);
        self::assertSame([$chunk['from'], $chunk['to'], []], [$cpo[0]->from, $cpo[0]->to, $cpo[0]->campaignIds]);
        // Список кампаний запрошен один раз — головным куском; заказ
        // берёт его из raw, а не вторым запросом (лимит 429).
        self::assertSame(1, $fetcher->campaignCalls);
    }

    public function testOrdinaryTickChunkOrdersNoReports(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $this->fetcher($container);
        $chunk = OzonAdvertisingWindows::lastDays(OzonAdvertisingWindows::today(new \DateTimeImmutable()), 30)[0];

        $this->syncStats($container, $account, $chunk['from'], $chunk['to']);

        self::assertSame([], $this->sent($container, OrderOzonAdSkuReportMessage::class));
    }

    public function testOrderedReportIsCheckedLaterByItsUuid(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $fetcher = $this->fetcher($container);

        $this->order($container, $account);

        self::assertCount(1, $fetcher->reportOrders);
        $envelopes = $this->sentEnvelopes($container, CheckOzonAdSkuReportMessage::class);
        self::assertCount(1, $envelopes);
        $check = $envelopes[0]->getMessage();
        self::assertInstanceOf(CheckOzonAdSkuReportMessage::class, $check);
        // UUID — из снятого ответа на заказ; проверка — не сразу, а через
        // 30 секунд: воркер не ждёт отчёт, пока тот формируется.
        self::assertSame('054cd190-6514-4465-8792-e3e11f396886', $check->uuid);
        self::assertSame(1, $check->attempt);
        self::assertSame(30_000, $envelopes[0]->last(DelayStamp::class)?->getDelay());
    }

    public function testOrdersReportIsOrderedForTheWholeOrganisationAndStoredAsReceived(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $fetcher = $this->fetcher($container);

        $handler = $container->get(OrderOzonAdSkuReportHandler::class);
        \assert($handler instanceof OrderOzonAdSkuReportHandler);
        $handler(new OrderOzonAdSkuReportMessage($account->companyId()->toRfc4122(), $account->id()->toRfc4122(), '2026-08-25', '2026-09-24', [], kind: OzonAdReportKind::CpoOrders));

        self::assertSame([['from' => '2026-08-25', 'to' => '2026-09-24']], $fetcher->cpoOrders);
        self::assertSame([], $fetcher->reportOrders);
        $checks = $this->sent($container, CheckOzonAdSkuReportMessage::class);
        self::assertCount(1, $checks);
        self::assertSame(OzonAdReportKind::CpoOrders, $checks[0]->kind);

        // Готовый отчёт — в raw своего типа, как есть: форма строки
        // неизвестна, разбора нет (ADR-026 п. 4).
        $check = $container->get(CheckOzonAdSkuReportHandler::class);
        \assert($check instanceof CheckOzonAdSkuReportHandler);
        $check($checks[0]);

        self::assertSame(
            [$this->fixture('statistics-json-many-2026-08-25.json')],
            $this->rawBodies($container, $account, MarketplaceReportType::OzonAdCpoOrders),
        );
        self::assertSame([], $this->rawBodies($container, $account, MarketplaceReportType::OzonAdSkuReport));
    }

    public function testRateLimitedOrderIsRetriedLaterWithinACeiling(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $fetcher = $this->fetcher($container);
        $fetcher->failOrdersWith($this->httpFailure(429));

        // У кабинета уже формируется отчёт — площадка держит «один
        // одновременно» сама, мы повторяем через минуту новым сообщением.
        $this->order($container, $account);

        $retries = $this->sentEnvelopes($container, OrderOzonAdSkuReportMessage::class);
        self::assertCount(1, $retries);
        $retry = $retries[0]->getMessage();
        self::assertInstanceOf(OrderOzonAdSkuReportMessage::class, $retry);
        self::assertSame(2, $retry->attempt);
        // Около минуты, с разбросом: отклонённые заказы не просыпаются
        // одной волной.
        $delay = $retries[0]->last(DelayStamp::class)?->getDelay();
        self::assertNotNull($delay);
        self::assertGreaterThanOrEqual(30_000, $delay);
        self::assertLessThanOrEqual(90_000, $delay);

        self::assertNotNull($retry->refusedSince);

        // Постоянный отказ (исчерпан суточный лимит) — не бесконечная
        // петля, а предупреждение, когда отказ длится дольше суток.
        $this->order($container, $account, attempt: 500, refusedSince: (new \DateTimeImmutable('-25 hours'))->format(\DateTimeInterface::ATOM));
        self::assertCount(1, $this->sentEnvelopes($container, OrderOzonAdSkuReportMessage::class));
        self::assertSame(1, $this->warningsContaining($container, 'Отчёт рекламы Ozon не заказан'));
    }

    public function testRejectedOrderGivesUpVisiblyWithoutRetry(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $fetcher = $this->fetcher($container);
        $fetcher->failOrdersWith($this->httpFailure(400));

        // Период глубже истории отчёта и подобное: повтор ответа
        // не изменит, отказ виден в журнале, очередь им не засоряется.
        $this->order($container, $account);

        self::assertSame([], $this->sentEnvelopes($container, OrderOzonAdSkuReportMessage::class));
        self::assertSame([], $this->sentEnvelopes($container, CheckOzonAdSkuReportMessage::class));
        self::assertSame(1, $this->warningsContaining($container, 'Отчёт рекламы Ozon не заказан'));
    }

    public function testReadyReportIsStoredAsReceived(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $this->fetcher($container);

        $this->check($container, $account, attempt: 1);

        self::assertSame(
            [$this->fixture('statistics-json-many-2026-08-25.json')],
            $this->rawBodies($container, $account, MarketplaceReportType::OzonAdSkuReport),
        );
        self::assertSame(['2026-08-25'], $this->rawPeriods($container, $account, MarketplaceReportType::OzonAdSkuReport));
        self::assertSame([], $this->sent($container, CheckOzonAdSkuReportMessage::class));
    }

    public function testNotReadyReportIsCheckedAgain(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $fetcher = $this->fetcher($container);
        $fetcher->reportStates(['IN_PROGRESS']);

        $this->check($container, $account, attempt: 3);

        $checks = $this->sentEnvelopes($container, CheckOzonAdSkuReportMessage::class);
        self::assertCount(1, $checks);
        $next = $checks[0]->getMessage();
        self::assertInstanceOf(CheckOzonAdSkuReportMessage::class, $next);
        self::assertSame(4, $next->attempt);
        self::assertSame(90_000, $checks[0]->last(DelayStamp::class)?->getDelay());
        self::assertSame([], $this->rawBodies($container, $account, MarketplaceReportType::OzonAdSkuReport));
    }

    public function testCheckCeilingAndPlatformErrorGiveUpVisibly(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $fetcher = $this->fetcher($container);
        $fetcher->reportStates(['IN_PROGRESS', 'ERROR']);

        // Потолок проверок и ERROR площадки — не тишина, а предупреждение
        // в журнал; отчёт не загружен, новой проверки нет.
        $this->check($container, $account, attempt: CheckOzonAdSkuReportHandler::MAX_ATTEMPTS);
        $this->check($container, $account, attempt: 1);

        self::assertSame([], $this->sent($container, CheckOzonAdSkuReportMessage::class));
        self::assertSame(2, $this->warningsContaining($container, 'Отчёт рекламы Ozon не загружен'));
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
        // День снимка — последний день куска из сообщения, а не часы
        // обработчика: повтор после полуночи попадает в тот же документ
        // (CLAUDE.md §4).
        self::assertSame(
            [$today->modify('-2 days')->format('Y-m-d')],
            $this->rawPeriods($container, $account, MarketplaceReportType::OzonAdCampaigns),
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

    public function testLateRejectionOfAReplacedKeyDoesNotBreakTheNewOne(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $connection = $this->connection($container);
        $this->fetcher($container, tokenStatus: 401, beforeRejection: static function () use ($connection, $account): void {
            // Клиент заменил ключ, пока запрос со старым был в пути:
            // замена поднимает версию подключения (ADR-008).
            $connection->executeStatement(
                'UPDATE marketplace_account SET version = version + 1 WHERE company_id = ? AND id = ?',
                [$account->companyId()->toRfc4122(), $account->id()->toRfc4122()],
            );
        });

        $this->syncStats($container, $account);

        // Отказ пришёл по старому ключу — новый он не ломает и письма
        // не порождает.
        self::assertSame(['state' => 'active', 'advertising_state' => 'active'], $this->states($container, $account));
        self::assertSame(0, $this->warningsContaining($container, 'Письмо о сломанном рекламном ключе'));
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

    private function syncStats(ContainerInterface $container, MarketplaceAccount $account, string $from = '2026-08-25', string $to = '2026-09-23', bool $withReports = false): void
    {
        $handler = $container->get(FetchOzonAdCampaignStatsHandler::class);
        \assert($handler instanceof FetchOzonAdCampaignStatsHandler);
        $handler(new FetchOzonAdCampaignStatsMessage($account->companyId()->toRfc4122(), $account->id()->toRfc4122(), $from, $to, $withReports));
    }

    private function order(ContainerInterface $container, MarketplaceAccount $account, int $attempt = 1, ?string $refusedSince = null): void
    {
        $handler = $container->get(OrderOzonAdSkuReportHandler::class);
        \assert($handler instanceof OrderOzonAdSkuReportHandler);
        $handler(new OrderOzonAdSkuReportMessage($account->companyId()->toRfc4122(), $account->id()->toRfc4122(), '2026-08-25', '2026-09-23', ['14275771', '16017246'], $attempt, $refusedSince));
    }

    private function check(ContainerInterface $container, MarketplaceAccount $account, int $attempt): void
    {
        $handler = $container->get(CheckOzonAdSkuReportHandler::class);
        \assert($handler instanceof CheckOzonAdSkuReportHandler);
        $handler(new CheckOzonAdSkuReportMessage($account->companyId()->toRfc4122(), $account->id()->toRfc4122(), '2026-08-25', '054cd190-6514-4465-8792-e3e11f396886', $attempt));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<Envelope>
     */
    private function sentEnvelopes(ContainerInterface $container, string $class): array
    {
        $transport = $container->get('messenger.transport.async_ingestion');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_filter(
            [...$transport->getSent()],
            static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof $class,
        ));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function sent(ContainerInterface $container, string $class): array
    {
        $messages = [];
        foreach ($this->sentEnvelopes($container, $class) as $envelope) {
            $message = $envelope->getMessage();
            \assert($message instanceof $class);
            $messages[] = $message;
        }

        return $messages;
    }

    private function httpFailure(int $status): \Throwable
    {
        $client = new MockHttpClient(new MockResponse('{}', ['http_code' => $status]));
        try {
            $client->request('POST', 'https://api-performance.ozon.ru/api/client/statistics/json')->getContent();
        } catch (\Throwable $failure) {
            return $failure;
        }

        throw new \LogicException('Ответ с кодом ошибки обязан бросить исключение.');
    }

    private function fetcher(ContainerInterface $container, int $tokenStatus = 200, ?\Closure $beforeRejection = null): FakeOzonAdvertisingFetcher
    {
        $fetcher = new FakeOzonAdvertisingFetcher($tokenStatus, $this->fixture('campaign-list.json'), $this->fixture('statistics-expense-2026-08-25.json'), $this->fixture('statistics-daily-2026-08-25.json'), $beforeRejection, $this->fixture('statistics-products-sku-2026-09-23.json'), $this->fixture('statistics-json-many-2026-08-25-request.json'), $this->fixture('statistics-json-many-2026-08-25.json'));
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
