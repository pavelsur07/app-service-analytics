<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\MarketplaceListingRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Domain\StockSnapshotFact;
use App\Ingestion\Infrastructure\Persistence\DoctrineStockSnapshotWriter;
use App\Ingestion\Infrastructure\Query\DemandMatrix\DemandMatrixCellQuery;
use App\Ingestion\Infrastructure\Query\DemandMatrix\DemandMatrixCellRow;
use App\Ingestion\Infrastructure\Query\DemandMatrix\DemandMatrixClustersQuery;
use App\Ingestion\Infrastructure\Query\DemandMatrix\DemandMatrixCursor;
use App\Ingestion\Infrastructure\Query\DemandMatrix\DemandMatrixSkuQuery;
use App\Ingestion\Infrastructure\Query\DemandMatrix\DemandMatrixSkuRow;
use App\Tests\Support\Builder\MarketplaceListingBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use App\Tests\Support\Builder\StockSnapshotFactBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Матрица спроса (docs/plan/ozon-demand-matrix-2-query.md). Сегодня —
 * 2026-09-26: окна 7/14/28 дней — с 19, 12 сентября и 29 августа
 * по 25 сентября.
 */
final class DemandMatrixQueryTest extends KernelTestCase
{
    private const string TODAY = '2026-09-26';
    private const string YESTERDAY = '2026-09-25';
    private const string OMSK = 'Омск';
    private const string KAZAN = 'Казань';
    private const string MOSCOW = 'Москва, МО и Дальние регионы';

    private Uuid $companyId;
    private Uuid $accountId;
    private int $postingSeq = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->companyId = Uuid::v7();
        $this->accountId = Uuid::v7();
    }

    public function testDemandBelongsToTheBuyerClusterNotTheShippingOne(): void
    {
        $this->sale('A', 3, clusterFrom: self::MOSCOW, clusterTo: self::OMSK);

        self::assertSame([self::OMSK => 3], $this->cellOrders('A'));
        self::assertSame([self::OMSK], array_column($this->clusters(), 'cluster'));
    }

    public function testCancelledOrdersAreNotDemand(): void
    {
        $this->sale('A', 2);
        $this->sale('A', 5, status: 'cancelled');

        self::assertSame(2, $this->skus()['A']->orders28);
    }

    public function testWindowsEndYesterday(): void
    {
        $this->sale('A', 1, day: self::YESTERDAY);
        $this->sale('A', 2, day: '2026-09-18');
        $this->sale('A', 4, day: '2026-09-11');
        $this->sale('A', 8, day: '2026-08-28');
        $this->sale('A', 16, day: self::TODAY);

        $sku = $this->skus()['A'];
        self::assertSame([1, 3, 7], [$sku->orders7, $sku->orders14, $sku->orders28]);
        // 7 / 28 = 0,25 штуки в день.
        self::assertSame(250, $sku->demandMilliPerDay);
    }

    public function testMissingBuyerClusterIsShownButNotInTheShare(): void
    {
        $this->sale('A', 3, clusterTo: self::OMSK);
        $this->sale('A', 1, clusterTo: self::KAZAN);
        $this->sale('A', 2, clusterTo: null);

        $sku = $this->skus()['A'];
        self::assertSame(6, $sku->orders28);
        self::assertSame(2, $sku->unknownClusterOrders28);

        $cells = $this->cells('A');
        self::assertSame(7500, $cells[self::OMSK]->shareBps);
        self::assertSame(2500, $cells[self::KAZAN]->shareBps);
        self::assertSame(2, $cells['']->orders28);
        self::assertNull($cells['']->shareBps);
        self::assertSame([self::OMSK, self::KAZAN, null], array_column($this->clusters(), 'cluster'));
    }

    public function testTrendNeedsMinSales(): void
    {
        // 4 из 10 — за последние 7 дней: (4 / 7) ÷ (10 / 28) = 1,6.
        $this->sale('A', 4, day: '2026-09-20');
        $this->sale('A', 6, day: '2026-09-01');
        // 9 заказов — меньше порога.
        $this->sale('B', 9, day: '2026-09-20');

        $skus = $this->skus();
        self::assertSame(16000, $skus['A']->trendBps);
        self::assertSame(16000, $this->cells('A')[self::OMSK]->trendBps);
        self::assertNull($skus['B']->trendBps);
    }

    public function testStockoutDaysMarkCellsIncludingEmptyOnes(): void
    {
        $this->sale('A', 3, clusterTo: self::OMSK);
        $this->sale('A', 1, clusterTo: self::KAZAN);
        // B даёт компании кластер «Москва»; у A там заказов нет.
        $this->sale('B', 1, clusterTo: self::MOSCOW);
        // Остаток A только в Омске, два полных дня; B в снимок не запрошен.
        foreach (['2026-09-24', self::YESTERDAY] as $day) {
            $this->snapshot($this->accountId, $day, [$this->stock('A', self::OMSK, $day)], ['A']);
        }

        $cells = $this->cells('A');
        self::assertSame(0, $cells[self::OMSK]->stockoutDays);
        self::assertSame(2, $cells[self::KAZAN]->stockoutDays);
        // Пустая ячейка с дефицитом — «спрос неизвестен», не «спроса нет».
        self::assertSame(0, $cells[self::MOSCOW]->orders28);
        self::assertSame(2, $cells[self::MOSCOW]->stockoutDays);
        // Без снимка дефицита нет — ячеек без заказов тоже.
        self::assertSame([self::MOSCOW], array_keys($this->cells('B')));
    }

    public function testDayWithoutACompleteSnapshotIsNotAStockoutDay(): void
    {
        $this->sale('A', 1, clusterTo: self::KAZAN);
        $second = Uuid::v7();
        $this->snapshot($this->accountId, '2026-09-24', [$this->stock('A', self::OMSK, '2026-09-24')], ['A']);
        $this->snapshot($this->accountId, self::YESTERDAY, [$this->stock('A', self::OMSK, self::YESTERDAY)], ['A']);
        // Второе подключение сняло остатки только вчера: 24-е неполное.
        $this->snapshot($second, self::YESTERDAY, [], ['X']);

        self::assertSame(1, $this->cells('A')[self::KAZAN]->stockoutDays);
    }

    public function testPagesAndSearch(): void
    {
        $this->sale('A', 3);
        $this->sale('B', 2);
        $this->sale('C', 1);
        $this->catalog('B', 'OFFER-blue');

        $first = $this->skuPage(limit: 2);
        self::assertSame(['A', 'B'], array_map(static fn (DemandMatrixSkuRow $r): string => $r->marketplaceSku, $first));
        $second = $this->skuPage(limit: 2, cursor: new DemandMatrixCursor(self::TODAY, '', 2, 'B'));
        self::assertSame(['C'], array_map(static fn (DemandMatrixSkuRow $r): string => $r->marketplaceSku, $second));

        self::assertSame(['B'], array_keys($this->skus('blue')));
        // `%` — буквальный символ, а не шаблон «всё».
        self::assertSame([], $this->skus('%'));
    }

    public function testAnotherCompanysDataIsNotVisible(): void
    {
        $this->sale('A', 2);
        $this->sale('A', 7, clusterTo: self::KAZAN, company: Uuid::v7());
        $this->sale('Z', 5, company: Uuid::v7());

        self::assertSame(['A'], array_keys($this->skus()));
        self::assertSame([self::OMSK => 2], $this->cellOrders('A'));
        self::assertSame([self::OMSK], array_column($this->clusters(), 'cluster'));
    }

    private function sale(
        string $sku,
        int $quantity,
        string $day = '2026-09-10',
        string $status = 'delivered',
        ?string $clusterFrom = self::OMSK,
        ?string $clusterTo = self::OMSK,
        ?Uuid $company = null,
    ): void {
        /** @var SalesFactRepository $repository */
        $repository = self::getContainer()->get(SalesFactRepository::class);
        $posting = 'P-'.(++$this->postingSeq);
        $repository->upsertAll([SalesFactBuilder::aSalesFact()
            ->withCompanyId($company ?? $this->companyId)
            ->withMarketplaceAccountId($this->accountId)
            ->withSourceRowId("{$posting}|{$sku}")
            ->withPostingNumber($posting)
            ->withMarketplaceSku($sku)
            ->withQuantity($quantity)
            ->withStatus($status)
            ->withBusinessDate(new \DateTimeImmutable($day))
            ->withClusters($clusterFrom, $clusterTo)
            ->build()]);
    }

    /**
     * @param list<StockSnapshotFact> $facts
     * @param list<string>            $requested
     */
    private function snapshot(Uuid $account, string $day, array $facts, array $requested): void
    {
        (new DoctrineStockSnapshotWriter($this->connection()))->replaceDay(
            $this->companyId->toRfc4122(),
            $account,
            new \DateTimeImmutable($day),
            new \DateTimeImmutable($day.' 00:30:00', new \DateTimeZone('UTC')),
            $requested,
            [],
            $facts,
        );
    }

    private function stock(string $sku, string $cluster, string $day): StockSnapshotFact
    {
        return StockSnapshotFactBuilder::aStockSnapshotFact()
            ->withCompanyId($this->companyId)
            ->withMarketplaceAccountId($this->accountId)
            ->withSnapshotDate(new \DateTimeImmutable($day))
            ->withSku($sku)
            ->withCluster(1, $cluster)
            ->withAvailable(5)
            ->build();
    }

    private function catalog(string $sku, string $offerId): void
    {
        /** @var MarketplaceListingRepository $listings */
        $listings = self::getContainer()->get(MarketplaceListingRepository::class);
        $listings->replaceForAccount($this->companyId->toRfc4122(), $this->accountId, [
            MarketplaceListingBuilder::aMarketplaceListing()
                ->withCompanyId($this->companyId)->withMarketplaceAccountId($this->accountId)
                ->withMarketplaceSku($sku)->withOfferId($offerId)->build(),
        ]);
    }

    /**
     * @return list<DemandMatrixSkuRow>
     */
    private function skuPage(int $limit = 50, ?DemandMatrixCursor $cursor = null, string $search = ''): array
    {
        $query = new DemandMatrixSkuQuery($this->connection());
        $rows = array_map(
            DemandMatrixSkuQuery::mapRow(...),
            $this->fetch($query->build($this->companyId->toRfc4122(), new \DateTimeImmutable(self::TODAY), $search, $limit, $cursor)),
        );

        return \array_slice($rows, 0, $limit);
    }

    /**
     * @return array<string, DemandMatrixSkuRow>
     */
    private function skus(string $search = ''): array
    {
        $bySku = [];
        foreach ($this->skuPage(search: $search) as $row) {
            $bySku[$row->marketplaceSku] = $row;
        }

        return $bySku;
    }

    /**
     * @return array<string, DemandMatrixCellRow> кластер → ячейка; '' — кластер не указан
     */
    private function cells(string $sku): array
    {
        $query = new DemandMatrixCellQuery($this->connection());
        $cells = [];
        foreach ($this->fetch($query->build($this->companyId->toRfc4122(), new \DateTimeImmutable(self::TODAY), [$sku])) as $row) {
            $cell = DemandMatrixCellQuery::mapRow($row);
            $cells[$cell->cluster ?? ''] = $cell;
        }

        return $cells;
    }

    /**
     * @return array<string, int>
     */
    private function cellOrders(string $sku): array
    {
        return array_map(static fn (DemandMatrixCellRow $c): int => $c->orders28, $this->cells($sku));
    }

    /**
     * @return list<array{cluster: ?string, orders28: int}>
     */
    private function clusters(): array
    {
        $query = new DemandMatrixClustersQuery($this->connection());

        return array_map(
            static fn (array $row): array => (array) DemandMatrixClustersQuery::mapRow($row),
            $this->fetch($query->build($this->companyId->toRfc4122(), new \DateTimeImmutable(self::TODAY))),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetch(QueryBuilder $query): array
    {
        return $this->connection()->fetchAllAssociative($query->getSQL(), $query->getParameters(), $query->getParameterTypes());
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }
}
