<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\Localization\BuildLocalizationReportAction;
use App\Ingestion\Application\Localization\LocalizationReport;
use App\Ingestion\Domain\MarketplaceExpenseFactRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Query\Localization\LocalizationSkuCursor;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\MarketplaceExpenseFactBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Отчёт «Локализация» (docs/plan/ozon-localization-report.md, решения 2 и 3).
 * Числа данных подобраны так, чтобы каждое правило давало своё значение:
 * округление половины от нуля, обратная логистика отдельно от прямой,
 * строка без начислений не занижает логистику на штуку, порог «мало
 * данных», изоляция по компании и по подключению.
 */
final class BuildLocalizationReportActionTest extends KernelTestCase
{
    private const string OMSK = 'Омск';
    private const string MOSCOW = 'Москва, МО и Дальние регионы';
    private const string FAR_EAST = 'Дальний Восток';

    private Uuid $companyId;
    private Uuid $accountId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->companyId = Uuid::v7();
        $this->accountId = Uuid::v7();
    }

    public function testSummaryAppliesEveryDefinition(): void
    {
        $this->seedScenario();

        $summary = $this->build()->summary;

        // 10 + 12 + 3 (SKU 100) + 2 (без кластеров) + 2 (SKU 200); отменённая
        // и вне периода не считаются.
        self::assertSame(29, $summary->quantity);
        self::assertSame(27, $summary->clusteredQuantity);
        self::assertSame(10, $summary->localQuantity);
        self::assertSame(17, $summary->nonlocalQuantity);
        // 10 / 27 = 0,37037 → 3704 bp.
        self::assertSame(3704, $summary->localShareBps);
        // Логистика начислена у 10 + 12 штук из 29 → 7586 bp.
        self::assertSame(22, $summary->chargedQuantity);
        self::assertSame(7586, $summary->chargedShareBps);
        // 1005 коп. / 10 шт. = 100,5 → 101: половина от нуля.
        self::assertSame(101, $summary->localForwardCostPerUnitMinor);
        // (2400 + 60) / 12 = 205; 3 штуки без начислений в знаменатель не входят,
        // а обратная логистика — в числитель.
        self::assertSame(205, $summary->nonlocalForwardCostPerUnitMinor);
        // 300 по доставленной + 200 по отменённой.
        self::assertSame(500, $summary->reverseCostMinor);
        self::assertSame('RUB', $summary->currency);
        self::assertTrue($summary->sufficientData);
    }

    public function testClustersShowWhereGoodsCameFromAndHideNoise(): void
    {
        $this->seedScenario();

        $report = $this->build();

        self::assertCount(2, $report->clusters);
        self::assertFalse($report->clustersTruncated);

        $omsk = $report->clusters[0];
        self::assertSame(self::OMSK, $omsk->clusterTo);
        self::assertSame(25, $omsk->metrics->clusteredQuantity);
        self::assertSame(4000, $omsk->metrics->localShareBps);
        self::assertCount(2, $omsk->topSources);
        self::assertSame(self::MOSCOW, $omsk->topSources[0]->cluster);
        self::assertSame(15, $omsk->topSources[0]->quantity);
        self::assertSame(6000, $omsk->topSources[0]->shareBps);
        self::assertSame(self::OMSK, $omsk->topSources[1]->cluster);

        // Две штуки — ниже порога: ни одной доли, ни логистики на штуку.
        $farEast = $report->clusters[1];
        self::assertSame(self::FAR_EAST, $farEast->clusterTo);
        self::assertFalse($farEast->metrics->sufficientData);
        self::assertNull($farEast->metrics->localShareBps);
        self::assertNull($farEast->metrics->chargedShareBps);
        self::assertNull($farEast->metrics->nonlocalForwardCostPerUnitMinor);
        self::assertSame(2, $farEast->topSources[0]->quantity);
        self::assertNull($farEast->topSources[0]->shareBps);
    }

    public function testSkuPagesGoByNonlocalQuantityWithoutGapsOrDuplicates(): void
    {
        $this->seedScenario();

        $first = $this->build(limit: 1);
        self::assertCount(1, $first->skus);
        self::assertSame('100', $first->skus[0]->marketplaceSku);
        self::assertSame(self::OMSK, $first->skus[0]->clusterTo);
        self::assertSame(self::MOSCOW, $first->skus[0]->mainSourceCluster);
        self::assertSame(15, $first->skus[0]->metrics->nonlocalQuantity);
        self::assertNotNull($first->nextCursor);

        $second = $this->build(limit: 1, cursor: $first->nextCursor);
        self::assertCount(1, $second->skus);
        self::assertSame('200', $second->skus[0]->marketplaceSku);
        self::assertSame(self::FAR_EAST, $second->skus[0]->clusterTo);
        self::assertNull($second->nextCursor);
    }

    public function testOtherCompanyAndOtherAccountDoNotLeakIntoTheReport(): void
    {
        $this->seedScenario();
        $otherCompany = Uuid::v7();

        // Чужая компания: те же номера отправлений и SKU, крупные суммы.
        $this->sale('P-L1', '100', 50, self::OMSK, self::OMSK, company: $otherCompany);
        $this->expense('P-L1', '100', 32, -999_999, accrualId: 9001, company: $otherCompany);
        // Своя компания, но другое подключение: логистика не должна
        // приклеиться к продаже с тем же номером отправления.
        $this->expense('P-N1', '100', 32, -888_888, accrualId: 9002, account: Uuid::v7());

        $summary = $this->build()->summary;

        self::assertSame(29, $summary->quantity);
        self::assertSame(101, $summary->localForwardCostPerUnitMinor);
        self::assertSame(205, $summary->nonlocalForwardCostPerUnitMinor);
    }

    public function testGroupsMadeOnlyOfCancelledPostingsAreNotListed(): void
    {
        $this->seedScenario();
        // Кластер доставки и пара SKU × кластер, где в периоде только
        // невыкуп: его обратная логистика есть в сводке, но строки нет.
        $this->sale('P-C2', '400', 4, self::MOSCOW, 'Калининград', status: 'cancelled');
        $this->expense('P-C2', '400', 59, -700, accrualId: 6);

        $report = $this->build();

        self::assertSame(1200, $report->summary->reverseCostMinor);
        self::assertSame([self::OMSK, self::FAR_EAST], array_map(static fn ($c): string => $c->clusterTo, $report->clusters));
        self::assertSame(['100', '200'], array_map(static fn ($s): string => $s->marketplaceSku, $report->skus));
    }

    public function testEmptyPeriodReturnsZeroSummaryInsteadOfFailing(): void
    {
        $report = $this->build();

        self::assertSame(0, $report->summary->quantity);
        self::assertNull($report->summary->localShareBps);
        self::assertNull($report->summary->reverseCostMinor);
        self::assertNull($report->summary->currency);
        self::assertSame([], $report->clusters);
        self::assertSame([], $report->skus);
        self::assertNull($report->nextCursor);
    }

    public function testMixedCurrenciesInOneGroupFailLoudly(): void
    {
        $this->sale('P-CUR', '300', 10, self::OMSK, self::OMSK);
        $this->expense('P-CUR', '300', 32, -1000, accrualId: 7001);
        $this->expense('P-CUR', '300', 29, -100, accrualId: 7002, currency: 'USD');

        $this->expectException(\UnexpectedValueException::class);

        $this->build();
    }

    private function seedScenario(): void
    {
        // SKU 100 в Омск: 10 локальных с логистикой 1005 коп.
        $this->sale('P-L1', '100', 10, self::OMSK, self::OMSK);
        $this->expense('P-L1', '100', 32, -1005, accrualId: 1);
        // 12 нелокальных из Москвы: прямая 2400 + 60, обратная 300.
        $this->sale('P-N1', '100', 12, self::MOSCOW, self::OMSK);
        $this->expense('P-N1', '100', 32, -2400, accrualId: 2);
        $this->expense('P-N1', '100', 29, -60, accrualId: 3);
        $this->expense('P-N1', '100', 59, -300, accrualId: 4);
        // 3 нелокальных, логистика ещё не начислена.
        $this->sale('P-N2', '100', 3, self::MOSCOW, self::OMSK);
        // Отменённая (у Ozon FBO так же выглядит невыкуп) — не в штуках и
        // долях, но её обратная логистика 200 в сумме обратной есть.
        $this->sale('P-C1', '100', 5, self::OMSK, self::OMSK, status: 'cancelled');
        $this->expense('P-C1', '100', 45, -200, accrualId: 5);
        // Вне периода — не в отчёте.
        $this->sale('P-OLD', '100', 7, self::OMSK, self::OMSK, date: '2026-06-30');
        // Кластеров нет — в штуках, но не в доле.
        $this->sale('P-U1', '100', 2, null, null);
        // SKU 200 на Дальний Восток: две штуки, ниже порога.
        $this->sale('P-F1', '200', 2, self::MOSCOW, self::FAR_EAST);
    }

    private function build(int $limit = 50, ?LocalizationSkuCursor $cursor = null): LocalizationReport
    {
        /** @var BuildLocalizationReportAction $action */
        $action = self::getContainer()->get(BuildLocalizationReportAction::class);

        return $action(
            $this->companyId->toRfc4122(),
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2026-07-30'),
            30,
            $limit,
            $cursor,
        );
    }

    private function sale(
        string $posting,
        string $sku,
        int $quantity,
        ?string $clusterFrom,
        ?string $clusterTo,
        string $status = 'delivered',
        string $date = '2026-07-10',
        ?Uuid $company = null,
    ): void {
        /** @var SalesFactRepository $repository */
        $repository = self::getContainer()->get(SalesFactRepository::class);
        SalesFactBuilder::aSalesFact()
            ->withCompanyId($company ?? $this->companyId)
            ->withMarketplaceAccountId($this->accountId)
            ->withSourceRowId("{$posting}|{$sku}")
            ->withPostingNumber($posting)
            ->withMarketplaceSku($sku)
            ->withQuantity($quantity)
            ->withStatus($status)
            ->withBusinessDate(new \DateTimeImmutable($date))
            ->withClusters($clusterFrom, $clusterTo)
            ->persistWith($repository);
    }

    private function expense(
        string $posting,
        string $sku,
        int $feeTypeId,
        int $amountMinor,
        int $accrualId,
        ?Uuid $company = null,
        ?Uuid $account = null,
        string $currency = 'RUB',
    ): void {
        /** @var MarketplaceExpenseFactRepository $repository */
        $repository = self::getContainer()->get(MarketplaceExpenseFactRepository::class);
        MarketplaceExpenseFactBuilder::aMarketplaceExpenseFact()
            ->withCompanyId($company ?? $this->companyId)
            ->withMarketplaceAccountId($account ?? $this->accountId)
            ->withAccrualId($accrualId)
            ->withUnitNumber($posting)
            ->withMarketplaceSku($sku)
            ->withFeeTypeId($feeTypeId)
            ->withAmount(Money::ofMinor($amountMinor, $currency))
            ->withBusinessDate(new \DateTimeImmutable('2026-07-15'))
            ->persistWith($repository);
    }
}
