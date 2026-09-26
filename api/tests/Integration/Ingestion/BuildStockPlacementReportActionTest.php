<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Ingestion\Application\StockPlacement\BuildStockPlacementReportAction;
use App\Ingestion\Application\StockPlacement\StockPlacementReport;
use App\Ingestion\Domain\MarketplaceListingRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Domain\StockSnapshotFact;
use App\Ingestion\Infrastructure\Persistence\DoctrineStockSnapshotWriter;
use App\Ingestion\Infrastructure\Query\StockPlacement\StockPlacementCursor;
use App\Ingestion\Infrastructure\Query\StockPlacement\StockPlacementRow;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\MarketplaceListingBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use App\Tests\Support\Builder\StockSnapshotFactBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Рекомендация раскладки (план C, решение 6; ADR-034). Целевое покрытие
 * 28 дней, срок поставки 7 — рекомендация = ⌈спрос × 35 − доступно −
 * в пути − заявлено⌉.
 */
final class BuildStockPlacementReportActionTest extends KernelTestCase
{
    private const string OMSK = 'Омск';
    private const string TODAY = '2026-09-26';

    private Uuid $companyId;
    private Uuid $accountId;
    private int $postingSeq = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->companyId = Uuid::v7();
        $this->accountId = Uuid::v7();
    }

    public function testStatusesCoverAndRecommendation(): void
    {
        $this->seedScenario();

        $rows = $this->bySku($this->build()->items);

        // A: 28 шт за 28 дней → 1/день; 5 шт — покрытие 5 < срока 7.
        self::assertSame('deficit', $rows['A']->status);
        self::assertSame(1000, $rows['A']->demandMilliPerDay);
        self::assertSame(5, $rows['A']->coverDays);
        self::assertSame(30, $rows['A']->recommended);
        // B: 0,5/день, 100 шт — 200 дней > 2 × 28.
        self::assertSame('surplus', $rows['B']->status);
        self::assertSame(200, $rows['B']->coverDays);
        self::assertSame(0, $rows['B']->recommended);
        // C: 5 продаж < 10 — рекомендации нет.
        self::assertSame('insufficient_data', $rows['C']->status);
        self::assertNull($rows['C']->recommended);
        // D: остаток без продаж.
        self::assertSame('no_sales', $rows['D']->status);
        self::assertNull($rows['D']->coverDays);
        self::assertSame(3, $rows['D']->available);
    }

    public function testInboundStockReducesTheRecommendation(): void
    {
        $this->snapshot(self::TODAY, [$this->stock('A', 5, transit: 6, requested: 4)]);
        $this->sales('A', 28);

        // ⌈35 − 5 − 6 − 4⌉ = 20.
        self::assertSame(20, $this->bySku($this->build()->items)['A']->recommended);
    }

    public function testSummaryAndAbc(): void
    {
        $this->seedScenario();

        $report = $this->build();

        self::assertSame(self::TODAY, $report->snapshotDate);
        // Сегодняшний снимок — остаток «сейчас», но в окно спроса (до
        // вчера) не входит.
        self::assertSame(0, $report->completeSnapshotDays);
        self::assertFalse($report->correctionApplied);
        self::assertSame(1, $report->deficitPositions);
        self::assertSame(30, $report->deficitUnits);
        self::assertSame(1, $report->surplusPositions);
        self::assertSame(1, $report->recommendedPositions);
        self::assertSame(30, $report->recommendedUnits);

        // Продажи 28 / 14 / 5 из 47: до B накоплено 60% — A, до C — 89% — B.
        $rows = $this->bySku($report->items);
        self::assertSame('A', $rows['A']->abcClass);
        self::assertSame('A', $rows['B']->abcClass);
        self::assertSame('B', $rows['C']->abcClass);
    }

    public function testDeficitCorrectionNeedsTheWholeWindowOfCompleteSnapshots(): void
    {
        // 28 полных снимков подряд; SKU E был в запросе каждый день,
        // а остаток в кластере — только первые 14 дней.
        for ($i = 28; $i >= 1; --$i) {
            $day = (new \DateTimeImmutable(self::TODAY))->modify("-{$i} days")->format('Y-m-d');
            $this->snapshot($day, $i > 14 ? [$this->stock('E', 1, day: $day)] : [], ['E']);
        }
        $this->sales('E', 14);

        $report = $this->build();
        $e = $this->bySku($report->items)['E'];

        self::assertTrue($report->correctionApplied);
        self::assertSame(28, $report->completeSnapshotDays);
        self::assertSame(14, $e->zeroDays);
        // 14 продаж за 14 дней с остатком — 1/день, а не 0,5.
        self::assertSame(1000, $e->demandMilliPerDay);
    }

    public function testPagesAndIsolation(): void
    {
        $this->seedScenario();
        // Чужая компания: те же SKU и кластер, крупные числа.
        $other = Uuid::v7();
        (new DoctrineStockSnapshotWriter($this->connection()))->replaceDay(
            $other->toRfc4122(),
            $this->accountId,
            new \DateTimeImmutable(self::TODAY),
            new \DateTimeImmutable(self::TODAY.' 01:00:00'),
            ['A'],
            [],
            [StockSnapshotFactBuilder::aStockSnapshotFact()->withCompanyId($other)->withMarketplaceAccountId($this->accountId)
                ->withSnapshotDate(new \DateTimeImmutable(self::TODAY))->withSku('A')->withCluster(1, self::OMSK)->withAvailable(9999)->build()],
        );
        $this->sales('A', 500, $other);

        $first = $this->build(limit: 1);
        self::assertSame(['A'], array_map(static fn ($r): string => $r->marketplaceSku, $first->items));
        self::assertSame(5, $first->items[0]->available);
        self::assertNotNull($first->nextCursor);

        $second = $this->build(limit: 1, cursor: $first->nextCursor);
        self::assertSame(['B'], array_map(static fn ($r): string => $r->marketplaceSku, $second->items));
    }

    public function testWithoutAFreshSnapshotStockIsUnknownNotZero(): void
    {
        // Последний полный снимок — пять дней назад: подключение перестало
        // снимать остатки. Его остаток за текущий не идёт (ADR-034).
        $day = (new \DateTimeImmutable(self::TODAY))->modify('-5 days')->format('Y-m-d');
        $this->snapshot($day, [$this->stock('A', 40, day: $day)]);
        $this->sales('A', 28);

        $report = $this->build();

        self::assertNull($report->snapshotDate);
        self::assertSame(1, $report->unknownPositions);
        $a = $this->bySku($report->items)['A'];
        self::assertSame('unknown_stock', $a->status);
        self::assertNull($a->available);
        self::assertNull($a->recommended);
    }

    public function testSecondCabinetWithAStaleSnapshotMakesTheSkuUnknown(): void
    {
        // Один SKU в двух кабинетах: у первого снимок свежий, у второго —
        // пятидневный. Сумма по компании неполна — не «5», а «неизвестно».
        $this->snapshot(self::TODAY, [$this->stock('A', 5)]);
        $second = $this->accountId;
        $this->accountId = Uuid::v7();
        $day = (new \DateTimeImmutable(self::TODAY))->modify('-5 days')->format('Y-m-d');
        $this->snapshot($day, [$this->stock('A', 40, day: $day)]);
        $this->sales('A', 28);
        $this->accountId = $second;

        $report = $this->build();
        $a = $this->bySku($report->items)['A'];

        self::assertSame('unknown_stock', $a->status);
        self::assertNull($a->available);
        self::assertNull($a->transit);
        self::assertNull($a->requested);
        self::assertSame(1, $report->staleAccounts);
    }

    public function testActiveCabinetWithCatalogButNoCompleteSnapshotCountsAsStale(): void
    {
        [$first, $second] = $this->cabinets(MarketplaceAccountState::Active);
        $this->accountId = $first;
        $this->snapshot(self::TODAY, [$this->stock('A', 5)]);
        $this->sales('A', 28);
        // Второй активный кабинет: A в каталоге, продаж и полного снимка
        // нет (прогон обрывается на последней пачке) — сумма неполна.
        $this->catalog($second, 'A');

        $report = $this->build();

        self::assertSame(1, $report->staleAccounts);
        self::assertSame('unknown_stock', $this->bySku($report->items)['A']->status);
    }

    public function testDisconnectedCabinetCatalogDoesNotMakeStockUnknown(): void
    {
        [$first, $second] = $this->cabinets(MarketplaceAccountState::Revoked);
        $this->accountId = $first;
        $this->snapshot(self::TODAY, [$this->stock('A', 5)]);
        $this->sales('A', 28);
        // Отозванный кабинет снимков не получает — его старый каталог
        // не должен навсегда делать SKU «неизвестным».
        $this->catalog($second, 'A');

        $report = $this->build();

        self::assertSame(0, $report->staleAccounts);
        self::assertSame('deficit', $this->bySku($report->items)['A']->status);
    }

    /**
     * Компания с двумя кабинетами Ozon в Identity: первый активный,
     * второй — в заданном состоянии.
     *
     * @return array{Uuid, Uuid}
     */
    private function cabinets(MarketplaceAccountState $secondState): array
    {
        /** @var CompanyRepository $companies */
        $companies = self::getContainer()->get(CompanyRepository::class);
        /** @var MarketplaceAccountRepository $accounts */
        $accounts = self::getContainer()->get(MarketplaceAccountRepository::class);
        $company = CompanyBuilder::aCompany()->persistWith($companies);
        $this->companyId = $company->id();
        $first = MarketplaceAccountBuilder::aMarketplaceAccount()->withCompany($company)
            ->withExternalShopId('shop-'.bin2hex(random_bytes(4)))->persistWith($companies, $accounts);
        $second = MarketplaceAccountBuilder::aMarketplaceAccount()->withCompany($company)
            ->withExternalShopId('shop-'.bin2hex(random_bytes(4)))->withState($secondState)->persistWith($companies, $accounts);

        return [$first->id(), $second->id()];
    }

    private function catalog(Uuid $account, string $sku): void
    {
        /** @var MarketplaceListingRepository $listings */
        $listings = self::getContainer()->get(MarketplaceListingRepository::class);
        $listings->replaceForAccount($this->companyId->toRfc4122(), $account, [
            MarketplaceListingBuilder::aMarketplaceListing()
                ->withCompanyId($this->companyId)->withMarketplaceAccountId($account)->withMarketplaceSku($sku)->build(),
        ]);
    }

    public function testSkuOutsideTheSnapshotRequestIsUnknownNotZero(): void
    {
        // Свежий снимок есть, но F в его запросе не было (товар появился
        // в каталоге позже) — отсутствие строки не ноль.
        $this->snapshot(self::TODAY, [$this->stock('A', 5)]);
        $this->sales('F', 20);

        $f = $this->bySku($this->build()->items)['F'];

        self::assertSame('unknown_stock', $f->status);
        self::assertNull($f->available);
    }

    public function testTodaysSalesAreNotDemand(): void
    {
        $this->snapshot(self::TODAY, [$this->stock('A', 5)]);
        /** @var SalesFactRepository $repository */
        $repository = self::getContainer()->get(SalesFactRepository::class);
        $repository->upsertAll([SalesFactBuilder::aSalesFact()->withCompanyId($this->companyId)->withMarketplaceAccountId($this->accountId)
            ->withSourceRowId('TODAY-1|A')->withPostingNumber('TODAY-1')->withMarketplaceSku('A')->withQuantity(50)
            ->withBusinessDate(new \DateTimeImmutable(self::TODAY))->withClusters('Москва, МО и Дальние регионы', self::OMSK)->build()]);

        self::assertSame(0, $this->bySku($this->build()->items)['A']->sold);
    }

    public function testStatusFilterHoldsAcrossPages(): void
    {
        $this->snapshot(self::TODAY, [$this->stock('A', 5), $this->stock('G', 1), $this->stock('B', 100)]);
        $this->sales('A', 28);
        $this->sales('G', 28);
        $this->sales('B', 14);

        $first = $this->build(limit: 1, status: 'deficit');
        self::assertNotNull($first->nextCursor);
        $second = $this->build(limit: 1, cursor: $first->nextCursor, status: 'deficit');

        $seen = array_map(static fn ($r): string => $r->status, [...$first->items, ...$second->items]);
        self::assertSame(['deficit', 'deficit'], $seen);
        self::assertNull($second->nextCursor);
    }

    private function seedScenario(): void
    {
        $this->snapshot(self::TODAY, [
            $this->stock('A', 5),
            $this->stock('B', 100),
            $this->stock('D', 3),
        ], ['A', 'B', 'C', 'D']);
        $this->sales('A', 28);
        $this->sales('B', 14);
        $this->sales('C', 5);
        // Отменённые не спрос.
        $this->sales('A', 50, status: 'cancelled');
    }

    /**
     * @param list<StockSnapshotFact> $facts
     * @param list<string>|null       $requested
     */
    private function snapshot(string $day, array $facts, ?array $requested = null): void
    {
        (new DoctrineStockSnapshotWriter($this->connection()))->replaceDay(
            $this->companyId->toRfc4122(),
            $this->accountId,
            new \DateTimeImmutable($day),
            new \DateTimeImmutable($day.' 00:30:00', new \DateTimeZone('UTC')),
            $requested ?? array_values(array_unique(array_map(static fn (StockSnapshotFact $f): string => $f->marketplaceSku(), $facts))),
            [],
            $facts,
        );
    }

    private function stock(string $sku, int $available, int $transit = 0, int $requested = 0, string $day = self::TODAY): StockSnapshotFact
    {
        return StockSnapshotFactBuilder::aStockSnapshotFact()
            ->withCompanyId($this->companyId)
            ->withMarketplaceAccountId($this->accountId)
            ->withSnapshotDate(new \DateTimeImmutable($day))
            ->withSku($sku)
            ->withCluster(1, self::OMSK)
            ->withAvailable($available)
            ->withInbound($transit, $requested)
            ->build();
    }

    /**
     * Продажи в окне 28 дней — по одной штуке, днями по кругу.
     */
    private function sales(string $sku, int $units, ?Uuid $company = null, string $status = 'delivered'): void
    {
        /** @var SalesFactRepository $repository */
        $repository = self::getContainer()->get(SalesFactRepository::class);
        $facts = [];
        for ($i = 0; $i < $units; ++$i) {
            $posting = 'P-'.(++$this->postingSeq);
            $facts[] = SalesFactBuilder::aSalesFact()
                ->withCompanyId($company ?? $this->companyId)
                ->withMarketplaceAccountId($this->accountId)
                ->withSourceRowId("{$posting}|{$sku}")
                ->withPostingNumber($posting)
                ->withMarketplaceSku($sku)
                ->withStatus($status)
                // Полные дни окна: вчера и 27 дней до него — сегодняшний
                // день ещё не закончился и в спрос не входит.
                ->withBusinessDate((new \DateTimeImmutable(self::TODAY))->modify('-'.(1 + $i % 28).' days'))
                ->withClusters('Москва, МО и Дальние регионы', self::OMSK)
                ->build();
        }
        $repository->upsertAll($facts);
    }

    private function build(int $limit = 50, ?StockPlacementCursor $cursor = null, ?string $status = null): StockPlacementReport
    {
        /** @var BuildStockPlacementReportAction $action */
        $action = self::getContainer()->get(BuildStockPlacementReportAction::class);

        return $action($this->companyId->toRfc4122(), new \DateTimeImmutable(self::TODAY), 28, 7, $status, $limit, $cursor);
    }

    /**
     * @param list<StockPlacementRow> $rows
     *
     * @return array<string, StockPlacementRow>
     */
    private function bySku(array $rows): array
    {
        $bySku = [];
        foreach ($rows as $row) {
            $bySku[$row->marketplaceSku] = $row;
        }

        return $bySku;
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }
}
