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

    /** Кампании с расходом за последний день снятого расхода (23.09.2026). */
    private const array SPENDING_ON_LAST_DAY = ['14275771', '16017246', '23253271', '24147313', '29088934'];

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
        $today = OzonAdvertisingWindows::today(new \DateTimeImmutable());
        $fetcher = $this->fetcher($container, expense: $this->expenseEndingOn($today));
        $chunk = OzonAdvertisingWindows::lastDays($today, 30)[0];

        $this->syncStats($container, $account, $chunk['from'], $chunk['to']);

        $expectedDays = [$today->format('Y-m-d'), $today->modify('-1 day')->format('Y-m-d')];
        self::assertSame($expectedDays, array_values(array_unique(array_column($fetcher->skuRequests, 'day'))));

        // products/sku получает кампании с расходом за этот день
        // (ADR-035 п. 4) — в снятом расходе последнего дня их пять,
        // все типа SKU, — и не больше десяти за запрос.
        $expectedIds = self::SPENDING_ON_LAST_DAY;
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

    public function testSkuOfFreshDaysBecomesFactsAsTheCabinetGaveThem(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $today = OzonAdvertisingWindows::today(new \DateTimeImmutable());
        $this->fetcher($container, expense: $this->expenseEndingOn($today));
        $chunk = OzonAdvertisingWindows::lastDays($today, 30)[0];

        $this->syncStats($container, $account, $chunk['from'], $chunk['to']);

        // Снятый ответ products/sku за 23.09 — 21 строка на 4 654,82 ₽;
        // суммы из строк как есть, со знаком расхода (ADR-035 п. 2).
        // Заглушка отдаёт его на оба дня и каждую пачку: одинаковые
        // тройки не удваиваются.
        self::assertSame(['rows' => 21, 'total' => -465482, 'dates' => '2026-09-23'], $this->factTotals($container, $account));
        self::assertSame(-119339, $this->factAmount($container, $account, '14275771|2026-09-23|286085455'));
        // Прослеживаемость (ADR-006): строка ссылается на raw-документ,
        // из которого получена её текущая версия. Второй день принёс
        // те же суммы, ответ получен позже — ссылка на последний
        // подтвердивший её документ.
        $factRaw = $this->factRawDocumentIds($container, $account);
        self::assertCount(1, $factRaw);
        self::assertContains($factRaw[0], $this->rawIdStrings($container, $account, MarketplaceReportType::OzonAdSkuDay));
    }

    public function testUnparsableSkuDayDoesNotStopTheRestOfTheChunk(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $today = OzonAdvertisingWindows::today(new \DateTimeImmutable());
        // Площадка сменила разделитель в products/sku: разбор отказывает.
        $fetcher = $this->fetcher($container, expense: $this->expenseEndingOn($today), sku: str_replace('"1193.39"', '"1193,39"', $this->fixture('statistics-products-sku-2026-09-23.json')));
        $chunk = OzonAdvertisingWindows::lastDays($today, 30)[0];

        $this->syncStats($container, $account, $chunk['from'], $chunk['to'], withReports: true);

        // Ответ в raw, фактов нет, отказ виден в журнале. Остальное
        // из куска выполнено: оба дня products/sku и заказ отчётов
        // (ADR-006: неудача разбора не отменяет загрузку).
        self::assertCount(2, $this->rawBodies($container, $account, MarketplaceReportType::OzonAdSkuDay));
        self::assertSame(0, $this->factTotals($container, $account)['rows']);
        self::assertSame(2, $this->warningsContaining($container, 'SKU-разбивка рекламы Ozon не разобрана'));
        self::assertSame(self::SPENDING_ON_LAST_DAY, $this->orderedSkuCampaigns($container));
        self::assertCount(2, array_unique(array_column($fetcher->skuRequests, 'day')));
    }

    public function testHistoricalChunkOrdersReportsForArchivedCampaignsWithSpend(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $this->fetcher($container, expense: $this->fixture('statistics-expense-2025-09-01.json'));

        $this->syncStats($container, $account, '2025-09-01', '2025-09-30', withReports: true);

        // Сентябрь 2025: расход у пяти кампаний. Две из них сегодня
        // в архиве — их разбивка заказывается (ADR-035 п. 4), иначе
        // история по ним потеряна. 5268079 — «Оплата за заказ»
        // (SEARCH_PROMO): списка товаров у неё нет, отчёт не заказывается.
        self::assertSame(['12387459', '14275771', '16017246', '17656929'], $this->orderedSkuCampaigns($container));
    }

    public function testCampaignWithSpendMissingFromTheListIsStillOrdered(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $list = json_decode($this->fixture('campaign-list.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($list);
        self::assertIsArray($list['list']);
        $list['list'] = array_values(array_filter($list['list'], static fn (mixed $c): bool => \is_array($c) && '12387459' !== $c['id']));
        $this->fetcher($container, expense: $this->fixture('statistics-expense-2025-09-01.json'), campaigns: json_encode($list, \JSON_THROW_ON_ERROR));

        $this->syncStats($container, $account, '2025-09-01', '2025-09-30', withReports: true);

        // Тип неизвестен — лишний отчёт дешевле потерянной разбивки.
        self::assertContains('12387459', $this->orderedSkuCampaigns($container));
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
        $today = OzonAdvertisingWindows::today(new \DateTimeImmutable());
        $fetcher = $this->fetcher($container, expense: $this->expenseEndingOn($today));
        $chunk = OzonAdvertisingWindows::lastDays($today, 30)[0];

        $this->syncStats($container, $account, $chunk['from'], $chunk['to'], withReports: true);

        // Вчера и сегодня отдаёт products/sku; отчёт — за остальные дни
        // куска (ADR-026 п. 4), по кампаниям с расходом в периоде отчёта
        // (ADR-035 п. 4), не больше десяти в заказе.
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
        self::assertSame(self::SPENDING_ON_LAST_DAY, $campaigns);

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
        // Форма строки заказов неизвестна — фактов из него нет (ADR-035 п. 7).
        self::assertSame(0, $this->factTotals($container, $account)['rows']);
    }

    public function testCheckQueuedBeforeTheKindFieldIsStillASkuReport(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $this->fetcher($container);

        // Сообщение, сериализованное до появления поля `kind`: очередь
        // восстанавливает его без конструктора, и свойство остаётся
        // неинициализированным. Обработчик обязан прочитать его как SKU-отчёт.
        $legacy = $this->withoutProperty(
            new CheckOzonAdSkuReportMessage($account->companyId()->toRfc4122(), $account->id()->toRfc4122(), '2026-08-25', '054cd190-6514-4465-8792-e3e11f396886', 1),
            'kind',
        );
        $handler = $container->get(CheckOzonAdSkuReportHandler::class);
        \assert($handler instanceof CheckOzonAdSkuReportHandler);
        $handler($legacy);

        self::assertCount(1, $this->rawBodies($container, $account, MarketplaceReportType::OzonAdSkuReport));
    }

    public function testOrderQueuedBeforeTheKindFieldIsStillASkuReport(): void
    {
        $container = $this->bootedContainer();
        $account = $this->account($container);
        $fetcher = $this->fetcher($container);

        $legacy = $this->withoutProperty(
            new OrderOzonAdSkuReportMessage($account->companyId()->toRfc4122(), $account->id()->toRfc4122(), '2026-08-25', '2026-09-23', ['14275771']),
            'kind',
        );
        $handler = $container->get(OrderOzonAdSkuReportHandler::class);
        \assert($handler instanceof OrderOzonAdSkuReportHandler);
        $handler($legacy);

        self::assertCount(1, $fetcher->reportOrders);
        self::assertSame([], $fetcher->cpoOrders);
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

        // После raw — разбор в факты (ADR-035 п. 3): 725 строк на
        // 158 960,62 ₽, суммы строк отчёта как есть. Итог расхода
        // кампаний за тот же месяц — 158 960,57 ₽: пять копеек —
        // построчное округление площадки, и прятать его нельзя.
        self::assertSame(['rows' => 725, 'total' => -15896062, 'dates' => '2026-08-25..2026-09-23'], $this->factTotals($container, $account));
        self::assertSame(
            $this->rawIdStrings($container, $account, MarketplaceReportType::OzonAdSkuReport),
            $this->factRawDocumentIds($container, $account),
        );

        // Повтор проверки того же отчёта — тот же результат (CLAUDE.md §4).
        $this->check($container, $account, attempt: 1);
        self::assertSame(['rows' => 725, 'total' => -15896062, 'dates' => '2026-08-25..2026-09-23'], $this->factTotals($container, $account));
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
    private function orderedSkuCampaigns(ContainerInterface $container): array
    {
        $campaigns = [];
        foreach ($this->sent($container, OrderOzonAdSkuReportMessage::class) as $order) {
            if (OzonAdReportKind::Sku === OzonAdReportKind::of($order->kind)) {
                $campaigns = [...$campaigns, ...$order->campaignIds];
            }
        }

        return $campaigns;
    }

    /**
     * @return array{rows: int, total: int, dates: string}
     */
    private function factTotals(ContainerInterface $container, MarketplaceAccount $account): array
    {
        $row = $this->connection($container)->fetchAssociative(
            <<<'SQL'
                SELECT COUNT(*) AS rows, COALESCE(SUM(amount_minor), 0) AS total,
                       COALESCE(MIN(business_date)::text, '') AS first, COALESCE(MAX(business_date)::text, '') AS last
                FROM ad_sku_expense_fact
                WHERE company_id = ? AND marketplace_account_id = ?
                SQL,
            [$account->companyId()->toRfc4122(), $account->id()->toRfc4122()],
        );
        self::assertIsArray($row);
        self::assertIsString($row['first']);
        self::assertIsString($row['last']);
        self::assertIsInt($row['rows']);
        self::assertTrue(\is_int($row['total']) || \is_string($row['total']));

        return [
            'rows' => $row['rows'],
            'total' => (int) $row['total'],
            'dates' => $row['first'] === $row['last'] ? $row['first'] : $row['first'].'..'.$row['last'],
        ];
    }

    private function factAmount(ContainerInterface $container, MarketplaceAccount $account, string $sourceRowId): int
    {
        $amount = $this->connection($container)->fetchOne(
            'SELECT amount_minor FROM ad_sku_expense_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?',
            [$account->companyId()->toRfc4122(), $account->id()->toRfc4122(), $sourceRowId],
        );
        self::assertIsInt($amount);

        return $amount;
    }

    /**
     * @return list<string>
     */
    private function factRawDocumentIds(ContainerInterface $container, MarketplaceAccount $account): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection($container)->fetchFirstColumn(
            'SELECT DISTINCT raw_document_id::text FROM ad_sku_expense_fact WHERE company_id = ? AND marketplace_account_id = ? ORDER BY 1',
            [$account->companyId()->toRfc4122(), $account->id()->toRfc4122()],
        );

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function rawIdStrings(ContainerInterface $container, MarketplaceAccount $account, string $reportType): array
    {
        $ids = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $this->rawIds($container, $account, $reportType));
        sort($ids);

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
        $transport = $container->get('messenger.transport.async_backfill');
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

    /**
     * Сообщение в том виде, в каком его восстановит очередь, если оно было
     * сериализовано до появления свойства: `unserialize` без этого свойства,
     * конструктор не вызывается.
     *
     * @template T of object
     *
     * @param T $message
     *
     * @return T
     */
    private function withoutProperty(object $message, string $property): object
    {
        $serialized = serialize($message);
        $stripped = preg_replace('/s:'.\strlen($property).':"'.$property.'";(?:N|s:\d+:"[^"]*");/', '', $serialized, 1, $count);
        self::assertSame(1, $count);
        \assert(\is_string($stripped));
        $stripped = preg_replace_callback('/^O:(\d+):"([^"]+)":(\d+):/', static fn (array $m): string => 'O:'.$m[1].':"'.$m[2].'":'.((int) $m[3] - 1).':', $stripped);
        \assert(\is_string($stripped));

        $restored = unserialize($stripped);
        self::assertInstanceOf($message::class, $restored);

        return $restored;
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

    private function fetcher(ContainerInterface $container, int $tokenStatus = 200, ?\Closure $beforeRejection = null, ?string $expense = null, ?string $campaigns = null, ?string $sku = null): FakeOzonAdvertisingFetcher
    {
        $fetcher = new FakeOzonAdvertisingFetcher($tokenStatus, $campaigns ?? $this->fixture('campaign-list.json'), $expense ?? $this->fixture('statistics-expense-2026-08-25.json'), $this->fixture('statistics-daily-2026-08-25.json'), $beforeRejection, $sku ?? $this->fixture('statistics-products-sku-2026-09-23.json'), $this->fixture('statistics-json-many-2026-08-25-request.json'), $this->fixture('statistics-json-many-2026-08-25.json'));
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

    /**
     * Снятый расход с датами, сдвинутыми так, что его последний день
     * (23.09.2026) приходится на $lastDay. Отбор кампаний головного куска
     * смотрит на расход вчера и сегодня, а фикстура снята в прошлом;
     * форма ответа и суммы остаются побайтовыми, меняются только даты.
     */
    private function expenseEndingOn(\DateTimeImmutable $lastDay): string
    {
        $shift = (int) (new \DateTimeImmutable('2026-09-23'))->diff(new \DateTimeImmutable($lastDay->format('Y-m-d')))->format('%r%a');
        $shifted = preg_replace_callback(
            '/"date":"(\d{4}-\d{2}-\d{2})"/',
            static fn (array $m): string => '"date":"'.(new \DateTimeImmutable($m[1]))->modify("{$shift} days")->format('Y-m-d').'"',
            $this->fixture('statistics-expense-2026-08-25.json'),
        );
        self::assertIsString($shifted);

        return $shifted;
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
