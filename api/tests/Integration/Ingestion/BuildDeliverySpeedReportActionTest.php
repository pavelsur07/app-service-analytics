<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\DeliverySpeed\BuildDeliverySpeedReportAction;
use App\Ingestion\Application\DeliverySpeed\DeliverySpeedReport;
use App\Ingestion\Domain\MarketplacePostingStatusRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Query\DeliverySpeed\DeliverySpeedSkuCursor;
use App\Tests\Support\Builder\MarketplacePostingStatusBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Отчёт «Скорость доставки» (docs/plan/ozon-delivery-speed-report.md).
 *
 * Заказ 2026-08-10 06:00 UTC (09:00 по Москве). Передача в доставку
 * наблюдена 08-11 10:00 UTC — в окне тика (шаг 15 мин, оценка −7,5 мин).
 * Локальные прибыли в ПВЗ 08-12 12:00 UTC — тоже тик: доставка
 * 2 дн 5 ч 52 мин 30 с = 193 950 с. Нелокальные замечены в ПВЗ 08-15
 * 00:30 UTC — 5-й день, только ночной рескан (шаг сутки, оценка −12 ч):
 * 4 дн 6 ч 30 мин = 369 000 с.
 */
final class BuildDeliverySpeedReportActionTest extends KernelTestCase
{
    private const string OMSK = 'Омск';
    private const string MOSCOW = 'Москва, МО и Дальние регионы';
    private const string ORDERED = '2026-08-10 06:00:00';

    private Uuid $companyId;
    private Uuid $accountId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->companyId = Uuid::v7();
        $this->accountId = Uuid::v7();
    }

    public function testSummaryEstimatesMomentsByPollStep(): void
    {
        $this->seedScenario();

        $report = $this->build();
        $summary = $report->summary;

        // 20 прибывших + 1 отменённая до прибытия видны вживую; ещё один
        // заказ начальной загрузки — только в числе отправлений периода.
        self::assertSame(22, $report->periodPostings);
        self::assertSame(21, $summary->postings);
        self::assertSame(20, $summary->arrivedPostings);
        self::assertSame(10, $summary->rescanArrivedPostings);
        self::assertSame(193_950, $summary->medianDeliverySeconds);
        self::assertSame(369_000, $summary->p90DeliverySeconds);
        // Передача 08-11 09:52:30 → 1 дн 3 ч 52 мин 30 с.
        self::assertSame(100_350, $summary->medianAssemblySeconds);
        self::assertSame(93_600, $summary->medianTransitSeconds);
        self::assertSame(193_950, $summary->medianLocalSeconds);
        self::assertSame(369_000, $summary->medianNonlocalSeconds);
        self::assertTrue($summary->sufficientData);
    }

    public function testLostHoursRankClustersAndSkus(): void
    {
        $this->seedScenario();

        $report = $this->build();

        // 10 × (369 000 − 193 950) / 3600 = 486,25 → 487 (вверх).
        self::assertSame(self::OMSK, $report->clusters[0]->clusterTo);
        self::assertSame(487, $report->clusters[0]->lostHours);

        self::assertCount(1, $report->skus);
        self::assertSame('NONLOCAL', $report->skus[0]->marketplaceSku);
        self::assertSame(487, $report->skus[0]->lostHours);
        self::assertSame(193_950, $report->skus[0]->clusterMedianLocalSeconds);
        self::assertSame(369_000, $report->skus[0]->clusterMedianNonlocalSeconds);

        $routes = array_map(static fn ($r): string => $r->clusterFrom.'→'.$r->clusterTo, $report->routes);
        self::assertContains(self::MOSCOW.'→'.self::OMSK, $routes);
        self::assertContains(self::OMSK.'→'.self::OMSK, $routes);
    }

    public function testBuyoutBySpeedBuckets(): void
    {
        $this->seedScenario();

        $buckets = $this->build()->buyoutBySpeed;

        self::assertSame([0, 3, 5, 8], array_map(static fn ($b): int => $b->minDays, $buckets));
        // Быстрые (2,2 дн) выкуплены, медленные (4,3 дн) — невыкуп.
        self::assertSame(10, $buckets[0]->postings);
        self::assertSame(10000, $buckets[0]->buyoutRateBps);
        self::assertSame(10, $buckets[1]->postings);
        self::assertSame(0, $buckets[1]->buyoutRateBps);
        self::assertSame(0, $buckets[2]->postings);
        self::assertNull($buckets[2]->buyoutRateBps);
    }

    public function testCourierDeliveryAndReturnedParcelCountAsArrivals(): void
    {
        // Курьер: прибытие — delivered/posting_delivered.
        $this->posting('C-1', 'SKU', self::OMSK, self::OMSK, [
            ['delivering', 'posting_transferred_to_courier_service', '2026-08-11 10:00:00'],
            ['delivered', 'posting_delivered', '2026-08-11 18:00:00'],
        ]);
        // Невыкуп: лежал в ПВЗ, потом отменён — до покупателя доехал.
        $this->posting('R-1', 'SKU', self::OMSK, self::OMSK, [
            ['delivering', 'posting_on_way_to_city', '2026-08-11 10:00:00'],
            ['delivering', 'posting_in_pickup_point', '2026-08-12 12:00:00'],
            ['cancelled', 'posting_canceled', '2026-08-20 03:30:00'],
        ]);

        $summary = $this->build()->summary;

        self::assertSame(2, $summary->arrivedPostings);
        // Меньше порога — медианы не отдаются.
        self::assertFalse($summary->sufficientData);
        self::assertNull($summary->medianDeliverySeconds);
    }

    public function testOtherCompanyDoesNotLeakIntoTheReport(): void
    {
        $this->seedScenario();
        $other = Uuid::v7();
        for ($i = 0; $i < 12; ++$i) {
            $this->posting("L-{$i}", 'LOCAL', self::OMSK, self::OMSK, [
                ['delivering', 'posting_on_way_to_city', '2026-08-10 07:00:00'],
                ['delivering', 'posting_in_pickup_point', '2026-08-10 08:00:00'],
            ], company: $other);
        }

        $summary = $this->build()->summary;

        self::assertSame(20, $summary->arrivedPostings);
        self::assertSame(193_950, $summary->medianDeliverySeconds);
    }

    public function testFirstObservationAfterTickWindowIsMeasuredFromWindowEnd(): void
    {
        // Замечено 08-13 00:30 UTC (03:30 МСК) — первые сутки после окна
        // тика. Предыдущий опрос — конец окна (08-13 00:00 МСК = 08-12
        // 21:00 UTC), а не сутки назад: оценка 08-12 22:45 UTC, доставка
        // 2 дн 16 ч 45 мин = 233 100 с. Вычет 12 ч дал бы момент раньше
        // опроса, на котором посылки в ПВЗ ещё не было.
        for ($i = 0; $i < 10; ++$i) {
            $this->posting("W-{$i}", 'SKU', self::OMSK, self::OMSK, [
                ['delivering', 'posting_on_way_to_city', '2026-08-11 10:00:00'],
                ['delivering', 'posting_in_pickup_point', '2026-08-13 00:30:00'],
            ]);
        }

        $summary = $this->build()->summary;

        self::assertSame(233_100, $summary->medianDeliverySeconds);
        self::assertSame(10, $summary->rescanArrivedPostings);
    }

    public function testStageMediansNeedTheirOwnSamples(): void
    {
        // Прибыли 10, но передача (любой delivering) наблюдалась лишь
        // у одного: остальные курьерские, сразу delivered. Медианы сборки
        // и пути не отдаются, общая — отдаётся.
        for ($i = 0; $i < 10; ++$i) {
            $events = [['delivered', 'posting_delivered', '2026-08-12 12:00:00']];
            if (0 === $i) {
                $events = [
                    ['delivering', 'posting_on_way_to_city', '2026-08-11 10:00:00'],
                    ['delivering', 'posting_in_pickup_point', '2026-08-12 12:00:00'],
                ];
            }
            $this->posting("S-{$i}", 'SKU', self::OMSK, self::OMSK, $events);
        }

        $summary = $this->build()->summary;

        self::assertSame(10, $summary->arrivedPostings);
        self::assertNotNull($summary->medianDeliverySeconds);
        self::assertNull($summary->medianAssemblySeconds);
        self::assertNull($summary->medianTransitSeconds);
    }

    public function testSkuKeysetPagesWithoutGapsOrDuplicates(): void
    {
        $this->seedScenario();
        for ($i = 0; $i < 3; ++$i) {
            $this->posting("N2-{$i}", 'NONLOCAL2', self::MOSCOW, self::OMSK, [
                ['delivering', 'posting_on_way_to_city', '2026-08-11 10:00:00'],
                ['delivering', 'posting_in_pickup_point', '2026-08-15 00:30:00'],
            ]);
        }

        $first = $this->build(limit: 1);
        self::assertSame(['NONLOCAL'], array_map(static fn ($r): string => $r->marketplaceSku, $first->skus));
        self::assertSame(487, $first->skus[0]->lostHours);
        self::assertNotNull($first->nextCursor);

        $second = $this->build(limit: 1, cursor: $first->nextCursor);
        // 3 × 175 050 / 3600 = 145,875 → 146 (вверх).
        self::assertSame(['NONLOCAL2'], array_map(static fn ($r): string => $r->marketplaceSku, $second->skus));
        self::assertSame(146, $second->skus[0]->lostHours);
        self::assertNull($second->nextCursor);
    }

    public function testPostingWithoutOrderMomentCountsInPeriodButNotInSpeed(): void
    {
        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        SalesFactBuilder::aSalesFact()
            ->withCompanyId($this->companyId)
            ->withMarketplaceAccountId($this->accountId)
            ->withSourceRowId('NO-MOMENT|SKU')
            ->withPostingNumber('NO-MOMENT')
            ->withBusinessDate(new \DateTimeImmutable('2026-08-10'))
            ->withOrderedAt(null)
            ->persistWith($sales);

        $report = $this->build();

        self::assertSame(1, $report->periodPostings);
        self::assertSame(0, $report->summary->postings);
    }

    public function testSkuPagesAndEmptyPeriod(): void
    {
        $empty = $this->build();
        self::assertSame(0, $empty->periodPostings);
        self::assertSame([], $empty->skus);
        self::assertNull($empty->summary->medianDeliverySeconds);
        self::assertSame(0, $empty->buyoutBySpeed[0]->postings);

        $this->seedScenario();
        $page = $this->build(limit: 1);
        self::assertCount(1, $page->skus);
        self::assertNull($page->nextCursor);
    }

    private function seedScenario(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->posting("L-{$i}", 'LOCAL', self::OMSK, self::OMSK, [
                ['delivering', 'posting_on_way_to_city', '2026-08-11 10:00:00'],
                ['delivering', 'posting_in_pickup_point', '2026-08-12 12:00:00'],
                ['delivered', 'posting_received', '2026-08-13 12:00:00'],
            ]);
            $this->posting("N-{$i}", 'NONLOCAL', self::MOSCOW, self::OMSK, [
                ['delivering', 'posting_on_way_to_city', '2026-08-11 10:00:00'],
                ['delivering', 'posting_in_pickup_point', '2026-08-15 00:30:00'],
                ['cancelled', 'posting_canceled', '2026-08-25 00:30:00'],
            ]);
        }
        // Отменён до передачи: виден вживую, но не прибыл.
        $this->posting('X-1', 'LOCAL', self::OMSK, self::OMSK, [
            ['cancelled', 'posting_canceled', '2026-08-10 09:00:00'],
        ]);
        // Начальная загрузка: впервые «наблюдён» через десять дней.
        $this->posting('OLD-1', 'LOCAL', self::OMSK, self::OMSK, [
            ['delivering', 'posting_in_pickup_point', '2026-08-20 00:30:00'],
        ], firstObserved: '2026-08-20 00:00:00');
    }

    /**
     * @param list<array{string, string, string}> $events [статус, подстатус, момент наблюдения UTC]
     */
    private function posting(
        string $posting,
        string $sku,
        string $clusterFrom,
        string $clusterTo,
        array $events,
        ?Uuid $company = null,
        string $firstObserved = '2026-08-10 06:10:00',
    ): void {
        $companyId = $company ?? $this->companyId;
        /** @var SalesFactRepository $sales */
        $sales = self::getContainer()->get(SalesFactRepository::class);
        SalesFactBuilder::aSalesFact()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($this->accountId)
            ->withSourceRowId("{$posting}|{$sku}")
            ->withPostingNumber($posting)
            ->withOrderNumber("ORDER-{$posting}")
            ->withMarketplaceSku($sku)
            ->withBusinessDate(new \DateTimeImmutable('2026-08-10'))
            ->withOrderedAt(new \DateTimeImmutable(self::ORDERED, new \DateTimeZone('UTC')))
            ->withClusters($clusterFrom, $clusterTo)
            ->persistWith($sales);

        $statuses = [$this->observation($companyId, $posting, 'awaiting_packaging', 'posting_created', $firstObserved)];
        foreach ($events as [$status, $substatus, $observedAt]) {
            $statuses[] = $this->observation($companyId, $posting, $status, $substatus, $observedAt);
        }
        /** @var MarketplacePostingStatusRepository $repository */
        $repository = self::getContainer()->get(MarketplacePostingStatusRepository::class);
        $repository->recordChanged($companyId->toRfc4122(), $statuses);
    }

    private function observation(Uuid $companyId, string $posting, string $status, string $substatus, string $observedAt): \App\Ingestion\Domain\MarketplacePostingStatus
    {
        return MarketplacePostingStatusBuilder::aMarketplacePostingStatus()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($this->accountId)
            ->withPostingNumber($posting)
            ->withOrderNumber("ORDER-{$posting}")
            ->withStatus($status, $substatus)
            ->withObservedAt(new \DateTimeImmutable($observedAt, new \DateTimeZone('UTC')))
            ->withRawDocumentId(Uuid::v7())
            ->build();
    }

    private function build(int $limit = 50, ?DeliverySpeedSkuCursor $cursor = null): DeliverySpeedReport
    {
        /** @var BuildDeliverySpeedReportAction $action */
        $action = self::getContainer()->get(BuildDeliverySpeedReportAction::class);

        return $action(
            $this->companyId->toRfc4122(),
            new \DateTimeImmutable('2026-08-01'),
            new \DateTimeImmutable('2026-08-30'),
            30,
            $limit,
            $cursor,
        );
    }
}
