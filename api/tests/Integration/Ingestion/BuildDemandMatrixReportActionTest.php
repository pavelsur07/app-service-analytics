<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Ingestion\Application\DemandMatrix\BuildDemandMatrixReportAction;
use App\Ingestion\Application\DemandMatrix\DemandMatrixReport;
use App\Ingestion\Application\DemandMatrix\DemandMatrixReportItem;
use App\Ingestion\Domain\MarketplaceDayLoadRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Query\DemandMatrix\DemandMatrixCellRow;
use App\Ingestion\Infrastructure\Query\DemandMatrix\DemandMatrixCursor;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\MarketplaceDayLoadBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Сценарий матрицы спроса (docs/plan/ozon-demand-matrix-3-api.md):
 * ячейки своей страницы, продолжение по курсору, полнота загрузки продаж.
 * Сегодня — 2026-09-26 по Москве, окно — 29 августа … 25 сентября.
 */
final class BuildDemandMatrixReportActionTest extends KernelTestCase
{
    private const string TODAY = '2026-09-26';
    private const string YESTERDAY = '2026-09-25';
    /** Полночь 26-го по Москве — 25-е, 21:00 UTC. */
    private const string AFTER_DAY_END_UTC = '2026-09-25 21:30:00';
    private const string BEFORE_DAY_END_UTC = '2026-09-25 20:30:00';

    private Uuid $companyId;
    private Uuid $accountId;
    private int $postingSeq = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        [$this->companyId, $this->accountId] = $this->companyWithCabinets(MarketplaceAccountState::Active);
    }

    public function testCellsBelongToTheirPageAndTheCursorContinues(): void
    {
        $this->sale('A', 3, 'Омск');
        $this->sale('A', 1, 'Казань');
        $this->sale('B', 2, 'Казань');
        $this->sale('C', 1, 'Омск');

        $first = $this->build(limit: 2);
        self::assertSame(['Омск', 'Казань'], array_map(static fn ($c): ?string => $c->cluster, $first->clusters));
        self::assertSame(['A', 'B'], $this->skus($first));
        self::assertSame(['Омск' => 3, 'Казань' => 1], $this->cellOrders($first->items[0] ?? null));
        self::assertSame(['Казань' => 2], $this->cellOrders($first->items[1] ?? null));
        self::assertNotNull($first->nextCursor);

        $second = $this->build(limit: 2, cursor: $first->nextCursor);
        self::assertSame(['C'], $this->skus($second));
        self::assertSame(['Омск' => 1], $this->cellOrders($second->items[0] ?? null));
        self::assertNull($second->nextCursor);
        // Колонки — те же на каждой странице.
        self::assertSame(['Омск', 'Казань'], array_map(static fn ($c): ?string => $c->cluster, $second->clusters));
    }

    public function testEmptyPageHasNoCells(): void
    {
        $report = $this->build();

        self::assertSame([], $report->items);
        self::assertSame([], $report->clusters);
        self::assertNull($report->nextCursor);
    }

    /**
     * Каждый случай — поверх успешно загруженных дней окна, кроме вчера:
     * 29 августа … 24 сентября, отмечены после окончания дня.
     *
     * @param list<array{string, ?string, bool}> $marks период, момент UTC (null — после полуночи), отказ
     */
    #[DataProvider('loads')]
    public function testIncompleteSalesAccounts(array $marks, int $expected, bool $fullWindow = true): void
    {
        if ($fullWindow) {
            for ($day = new \DateTimeImmutable('2026-08-29'); $day < new \DateTimeImmutable(self::YESTERDAY); $day = $day->modify('+1 day')) {
                $marks = [[$day->format('Y-m-d'), null, false], ...$marks];
            }
        }
        foreach ($marks as [$period, $at, $failed]) {
            $mark = MarketplaceDayLoadBuilder::aMarketplaceDayLoad()
                ->withCompanyId($this->companyId)
                ->withMarketplaceAccountId($this->accountId)
                ->withReportType(MarketplaceReportType::OzonPostingFboList)
                ->withPeriod(new \DateTimeImmutable($period))
                ->withAt(new \DateTimeImmutable($at ?? self::AFTER_DAY_END_UTC, new \DateTimeZone('UTC')));
            ($failed ? $mark->failed() : $mark->completed())->persistWith($this->dayLoads());
        }

        self::assertSame($expected, $this->build()->incompleteSalesAccounts);
    }

    /** @return iterable<string, array{list<array{string, ?string, bool}>, int}> */
    public static function loads(): iterable
    {
        yield 'yesterday loaded after it ended' => [[[self::YESTERDAY, null, false]], 0];
        yield 'yesterday loaded only before it ended' => [[[self::YESTERDAY, self::BEFORE_DAY_END_UTC, false]], 1];
        yield 'no mark at all' => [[], 1, false];
        yield 'a day of the window without a mark' => [[[self::YESTERDAY, null, false]], 1, false];
        yield 'yesterday failed after success' => [[[self::YESTERDAY, null, false], [self::YESTERDAY, '2026-09-25 22:00:00', true]], 1];
        yield 'a failed day inside the window' => [[[self::YESTERDAY, null, false], ['2026-09-10', null, true]], 1];
        yield 'a failed day before the window' => [[[self::YESTERDAY, null, false], ['2026-08-28', null, true]], 0];
    }

    public function testRevokedCabinetIsNotCountedButBrokenIs(): void
    {
        [$this->companyId] = $this->companyWithCabinets(MarketplaceAccountState::Active, MarketplaceAccountState::Revoked);
        self::assertSame(1, $this->build()->incompleteSalesAccounts);

        [$this->companyId] = $this->companyWithCabinets(MarketplaceAccountState::Active, MarketplaceAccountState::Broken);
        self::assertSame(2, $this->build()->incompleteSalesAccounts);
    }

    private function sale(string $sku, int $quantity, string $cluster): void
    {
        /** @var SalesFactRepository $repository */
        $repository = self::getContainer()->get(SalesFactRepository::class);
        $posting = 'P-'.(++$this->postingSeq);
        $repository->upsertAll([SalesFactBuilder::aSalesFact()
            ->withCompanyId($this->companyId)
            ->withMarketplaceAccountId($this->accountId)
            ->withSourceRowId("{$posting}|{$sku}")
            ->withPostingNumber($posting)
            ->withMarketplaceSku($sku)
            ->withQuantity($quantity)
            ->withBusinessDate(new \DateTimeImmutable('2026-09-10'))
            ->withClusters($cluster, $cluster)
            ->build()]);
    }

    /**
     * Компания с кабинетами Ozon в Identity в заданных состояниях.
     *
     * @return array{Uuid, Uuid}
     */
    private function companyWithCabinets(MarketplaceAccountState ...$states): array
    {
        /** @var CompanyRepository $companies */
        $companies = self::getContainer()->get(CompanyRepository::class);
        /** @var MarketplaceAccountRepository $accounts */
        $accounts = self::getContainer()->get(MarketplaceAccountRepository::class);
        $company = CompanyBuilder::aCompany()->persistWith($companies);
        $first = null;
        foreach ($states as $state) {
            $account = MarketplaceAccountBuilder::aMarketplaceAccount()->withCompany($company)
                ->withExternalShopId('shop-'.bin2hex(random_bytes(4)))->withState($state)->persistWith($companies, $accounts);
            $first ??= $account->id();
        }

        return [$company->id(), $first ?? throw new \LogicException('No cabinets.')];
    }

    private function build(int $limit = 50, ?DemandMatrixCursor $cursor = null): DemandMatrixReport
    {
        /** @var BuildDemandMatrixReportAction $action */
        $action = self::getContainer()->get(BuildDemandMatrixReportAction::class);

        return $action($this->companyId->toRfc4122(), new \DateTimeImmutable(self::TODAY, new \DateTimeZone('Europe/Moscow')), '', $limit, $cursor);
    }

    private function dayLoads(): MarketplaceDayLoadRepository
    {
        /** @var MarketplaceDayLoadRepository $repository */
        $repository = self::getContainer()->get(MarketplaceDayLoadRepository::class);

        return $repository;
    }

    /**
     * @return list<string>
     */
    private function skus(DemandMatrixReport $report): array
    {
        return array_map(static fn (DemandMatrixReportItem $item): string => $item->sku->marketplaceSku, $report->items);
    }

    /**
     * @return array<string, int>
     */
    private function cellOrders(?DemandMatrixReportItem $item): array
    {
        self::assertNotNull($item);
        $orders = [];
        foreach ($item->cells as $cell) {
            self::assertInstanceOf(DemandMatrixCellRow::class, $cell);
            $orders[$cell->cluster ?? ''] = $cell->orders28;
        }

        return $orders;
    }
}
