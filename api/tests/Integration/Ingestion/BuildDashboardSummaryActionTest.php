<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Ingestion\Application\Buyout\BuildBuyoutRateReportAction;
use App\Ingestion\Application\Dashboard\BuildDashboardSummaryAction;
use App\Ingestion\Application\Dashboard\DashboardSummary;
use App\Ingestion\Domain\MarketplaceDayLoadRepository;
use App\Ingestion\Domain\MarketplaceExpenseFactRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Domain\OzonFeeTypeNames;
use App\Ingestion\Domain\SalesFactRepository;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceDayLoadBuilder;
use App\Tests\Support\Builder\MarketplaceExpenseFactBuilder;
use App\Tests\Support\Builder\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Сводка главной (docs/plan/dashboard-widgets.md): заказы по дате заказа,
 * деньги — по дате начисления, оба периода одним проходом.
 *
 * Обязательное покрытие ADR-005: денежная арифметика (ДРР, цена заказа,
 * изменения) и изоляция между компаниями.
 *
 * Выбранный период — 8–14 августа 2026, сравнение — 1–7 августа.
 */
final class BuildDashboardSummaryActionTest extends KernelTestCase
{
    private const string ACCOUNT_ID = '019fe6ea-cd99-7af8-bf4a-623a5a31cf7b';
    private const string SECOND_ACCOUNT_ID = '019fe6ea-cd99-7af8-bf4a-623a5a31cf7c';

    private int $accrualId = 1;

    public function testOrdersCountEveryStatusByOrderDateWithinThePeriod(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->sale($container, $company, '2026-08-08', 'delivered', 2, 200_000);
        $this->sale($container, $company, '2026-08-14', 'cancelled', 1, 50_000);
        $this->sale($container, $company, '2026-08-11', 'awaiting_packaging', 1, 30_000);
        // Соседние дни — в период сравнения и мимо обоих.
        $this->sale($container, $company, '2026-08-07', 'delivered', 1, 70_000);
        $this->sale($container, $company, '2026-08-15', 'delivered', 5, 999_000);

        $summary = $this->summary($container, $company);

        // Отмена входит: «Заказано» кабинета и экрана выкупа — все заказы.
        self::assertSame(4, $summary->current->metrics->ordersQuantity);
        self::assertSame(280_000, $summary->current->metrics->ordersAmountMinor);
        self::assertSame(1, $summary->comparison->metrics->ordersQuantity);
        self::assertSame(70_000, $summary->comparison->metrics->ordersAmountMinor);
    }

    public function testSalesAndReturnsAreRevenueRowsByAccrualDate(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->accrual($container, $company, '2026-08-09', OzonFeeTypeNames::REVENUE, 240_200, 'P-1');
        $this->accrual($container, $company, '2026-08-10', OzonFeeTypeNames::REVENUE, 100_000, 'P-2');
        $this->accrual($container, $company, '2026-08-12', OzonFeeTypeNames::REVENUE, -240_200, 'P-1');
        // Комиссия — не продажа и не реклама.
        $this->accrual($container, $company, '2026-08-09', OzonFeeTypeNames::SALE_COMMISSION, -110_492, 'P-1');

        $metrics = $this->summary($container, $company)->current->metrics;

        self::assertSame(2, $metrics->salesQuantity);
        self::assertSame(340_200, $metrics->salesAmountMinor);
        self::assertSame(1, $metrics->returnsQuantity);
        self::assertSame(240_200, $metrics->returnsAmountMinor);
        self::assertSame(0, $metrics->advertisingMinor);
    }

    public function testRevenueRowOfSeveralUnitsCountsEveryUnit(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        // Одна строка выручки на три штуки (ADR-038) — три продажи,
        // а не одна; цена продажи — на три штуки.
        $this->accrual($container, $company, '2026-08-09', OzonFeeTypeNames::REVENUE, 300_000, 'P-1', 3);
        $this->accrual($container, $company, '2026-08-09', OzonFeeTypeNames::PAY_PER_CLICK, -30_000, '');

        $metrics = $this->summary($container, $company)->current->metrics;

        self::assertSame(3, $metrics->salesQuantity);
        self::assertSame(300_000, $metrics->salesAmountMinor);
        self::assertSame(10_000, $metrics->costPerSaleMinor);
    }

    public function testAdvertisingIsEveryPromotionTypeAndNotInstalments(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->accrual($container, $company, '2026-08-08', OzonFeeTypeNames::PAY_PER_CLICK, -30_000, '29088934');
        $this->accrual($container, $company, '2026-08-08', 96, -5_000, '');
        $this->accrual($container, $company, '2026-08-08', 54, -1_000, '');
        // Реклама в соцсетях и сбор первых отзывов — продвижение, хоть
        // у первого клиента их ещё не встречалось.
        $this->accrual($container, $company, '2026-08-08', 87, -2_000, '');
        $this->accrual($container, $company, '2026-08-08', 116, -300, '');
        // Подписка Premium и бонусы продавца — не плата за показ.
        $this->accrual($container, $company, '2026-08-08', 52, -9_000, '');
        $this->accrual($container, $company, '2026-08-08', 48, -9_000, '');
        // Сторно клика площадкой уменьшает расход.
        $this->accrual($container, $company, '2026-08-09', OzonFeeTypeNames::PAY_PER_CLICK, 500, '29088934');
        // «Рассрочка» — в группе кабинета «Продвижение», но не реклама.
        $this->accrual($container, $company, '2026-08-08', 22, -7_000, 'P-1');

        $metrics = $this->summary($container, $company)->current->metrics;

        self::assertSame(37_800, $metrics->advertisingMinor);
    }

    public function testAdvertisingRatiosRoundHalfAwayFromZeroAndAreNullWithoutDenominator(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->sale($container, $company, '2026-08-08', 'delivered', 1, 100_000);
        $this->sale($container, $company, '2026-08-09', 'delivered', 1, 100_000);
        $this->sale($container, $company, '2026-08-10', 'delivered', 1, 100_000);
        $this->accrual($container, $company, '2026-08-08', OzonFeeTypeNames::PAY_PER_CLICK, -10_001, '1');

        $metrics = $this->summary($container, $company)->current->metrics;

        // 100,01 ₽ на три заказа — 33,337 ₽: 3334 копейки.
        self::assertSame(3_334, $metrics->costPerOrderMinor);
        // 10 001 / 300 000 = 3,3337 % — 333,37 б.п.: 333.
        self::assertSame(333, $metrics->drrOrdersBps);
        // Продаж нет — цены продажи и ДРР от продаж нет, а не ноль.
        self::assertNull($metrics->costPerSaleMinor);
        self::assertNull($metrics->drrSalesBps);
    }

    public function testLogisticsSumsNetAndCountsChargedPostings(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->accrual($container, $company, '2026-08-08', 32, -6_900, 'P-1');
        $this->accrual($container, $company, '2026-08-09', 98, -1_500, 'P-1');
        $this->accrual($container, $company, '2026-08-09', 32, -7_100, 'P-2');
        // Сторно логистики P-2: сумма нетто, отправление осталось списанием.
        $this->accrual($container, $company, '2026-08-10', 32, 2_000, 'P-2');
        // Начисление на кабинет без номера — в сумме, но не отправление.
        $this->accrual($container, $company, '2026-08-10', 98, -300, '');
        // Доставка партнёром до места выдачи — то же отправление P-1, не третье.
        $this->accrual($container, $company, '2026-08-10', 29, -800, 'P-1');
        // B2C-доставка: отдельное отправление P-6 — логистика, партнёром и силами Ozon.
        $this->accrual($container, $company, '2026-08-10', 110, -400, 'P-6');
        $this->accrual($container, $company, '2026-08-10', 111, -200, 'P-6');
        $this->accrual($container, $company, '2026-08-10', 114, -900, 'P-6');
        $this->accrual($container, $company, '2026-08-11', 59, -5_000, 'P-3');
        $this->accrual($container, $company, '2026-08-11', 2, -1_000, 'P-3');
        $this->accrual($container, $company, '2026-08-11', 9, -1_500, 'P-4');
        // Обработка возврата партнёром — то же отправление P-3, не второе.
        $this->accrual($container, $company, '2026-08-12', 45, -1_500, 'P-3');
        // B2C-возврат — отдельное отправление P-7: обработка и обратная логистика.
        $this->accrual($container, $company, '2026-08-12', 113, -700, 'P-7');
        $this->accrual($container, $company, '2026-08-12', 115, -600, 'P-7');
        // «Последняя миля» в наборы логистики не входит.
        $this->accrual($container, $company, '2026-08-11', 28, -9_999, 'P-5');

        $metrics = $this->summary($container, $company)->current->metrics;

        self::assertSame(6_900 + 1_500 + 7_100 - 2_000 + 300 + 800 + 400 + 200 + 900, $metrics->deliveryLogisticsMinor);
        self::assertSame(3, $metrics->deliveryCount);
        self::assertSame(5_000 + 1_000 + 1_500 + 1_500 + 700 + 600, $metrics->returnLogisticsMinor);
        self::assertSame(3, $metrics->returnLogisticsCount);
    }

    public function testChangesComparePeriodsWhenBothAreFullyLoaded(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);
        $this->loaded($container, $company, '2026-07-20');

        $this->sale($container, $company, '2026-08-03', 'delivered', 4, 400_000);
        $this->sale($container, $company, '2026-08-12', 'delivered', 5, 450_000);
        $this->accrual($container, $company, '2026-08-03', OzonFeeTypeNames::PAY_PER_CLICK, -40_000, '1');
        $this->accrual($container, $company, '2026-08-12', OzonFeeTypeNames::PAY_PER_CLICK, -54_000, '1');

        $summary = $this->summary($container, $company);

        self::assertTrue($summary->comparison->dataComplete);
        // 450 000 против 400 000 — +12,5 %.
        self::assertSame(1_250, $summary->changes->ordersAmountBps);
        self::assertSame(2_500, $summary->changes->ordersQuantityBps);
        self::assertSame(3_500, $summary->changes->advertisingBps);
        // ДРР 12 % против 10 % — +2 п.п., а не «+20 %».
        self::assertSame(1_200, $summary->current->metrics->drrOrdersBps);
        self::assertSame(200, $summary->changes->drrOrdersPointsBps);
        // Цена заказа 108 ₽ против 100 ₽ — +8 %.
        self::assertSame(800, $summary->changes->costPerOrderBps);
        // Продаж нет ни в одном периоде — сравнивать не с чем.
        self::assertNull($summary->changes->salesAmountBps);
    }

    public function testComparisonBeforeTheFirstLoadHasNoChanges(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);
        // Данные с 5 августа: период сравнения 1–7 августа начинается раньше.
        $this->loaded($container, $company, '2026-08-05');

        $this->sale($container, $company, '2026-08-06', 'delivered', 1, 10_000);
        $this->sale($container, $company, '2026-08-12', 'delivered', 9, 900_000);

        $summary = $this->summary($container, $company);

        self::assertFalse($summary->comparison->dataComplete);
        self::assertTrue($summary->current->dataComplete);
        // Иначе главная показала бы рост в 90 раз от неполной недели.
        self::assertNull($summary->changes->ordersAmountBps);
    }

    public function testSecondCabinetConnectedDuringComparisonHasNoChanges(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);
        $this->loaded($container, $company, '2026-07-01');
        // Второй кабинет подключён 4 августа — посреди периода сравнения.
        $this->loaded($container, $company, '2026-08-04', '2026-08-14', self::SECOND_ACCOUNT_ID);

        $summary = $this->summary($container, $company);

        self::assertFalse($summary->comparison->dataComplete);
        self::assertTrue($summary->current->dataComplete);
        self::assertNull($summary->changes->ordersAmountBps);
    }

    public function testStalledSyncMakesThePeriodIncomplete(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);
        // Синхронизация встала 12 августа: конец выбранного периода не загружен.
        $this->loaded($container, $company, '2026-07-01', '2026-08-12');

        $summary = $this->summary($container, $company);

        self::assertTrue($summary->comparison->dataComplete);
        self::assertFalse($summary->current->dataComplete);
        self::assertNull($summary->changes->ordersAmountBps);
    }

    public function testCabinetStoppedBeforeThePeriodDoesNotMakeItIncomplete(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);
        $this->loaded($container, $company, '2026-07-01');
        // Старый кабинет отключён в июле — в периодах его просто нет.
        $this->loaded($container, $company, '2026-06-01', '2026-07-15', self::SECOND_ACCOUNT_ID);

        $summary = $this->summary($container, $company);

        self::assertTrue($summary->comparison->dataComplete);
        self::assertTrue($summary->current->dataComplete);
    }

    public function testDayMissingFromBothSourcesInsideThePeriodMakesItIncomplete(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);
        // 3 августа синхронизация не прошла вовсе: ни отправлений,
        // ни начислений — границы загружены, середина нет.
        $this->loaded($container, $company, '2026-07-25', '2026-08-14', self::ACCOUNT_ID, ['2026-08-03']);

        $summary = $this->summary($container, $company);

        self::assertSame(0, $summary->comparison->daysWithoutAccruals);
        self::assertFalse($summary->comparison->dataComplete);
        self::assertTrue($summary->current->dataComplete);
        self::assertNull($summary->changes->ordersAmountBps);
    }

    public function testDayWithSalesButWithoutAccrualsMakesThePeriodIncomplete(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);
        $this->loaded($container, $company, '2026-07-25', '2026-08-14', self::ACCOUNT_ID, ['2026-08-10']);
        // 10 августа продажи загружены, а начисления — нет.
        $this->rawDocument($container, $company, MarketplaceReportType::OzonPostingFboList, '2026-08-10');

        $summary = $this->summary($container, $company);

        self::assertSame(1, $summary->current->daysWithoutAccruals);
        self::assertFalse($summary->current->dataComplete);
        self::assertNull($summary->changes->ordersAmountBps);
    }

    public function testDayWhoseLastLoadFailedMakesThePeriodIncompleteUntilARepeat(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);
        $this->loaded($container, $company, '2026-07-25');
        /** @var MarketplaceDayLoadRepository $dayLoads */
        $dayLoads = $container->get(MarketplaceDayLoadRepository::class);
        $mark = MarketplaceDayLoadBuilder::aMarketplaceDayLoad()
            ->withCompanyId($company->id())
            ->withMarketplaceAccountId(Uuid::fromString(self::ACCOUNT_ID))
            ->withReportType(MarketplaceReportType::OzonAccrualByDay)
            ->withPeriod(new \DateTimeImmutable('2026-08-10'));

        // Отказ того же дня у другой компании — её отметка, не наша.
        $mark->withCompanyId(Uuid::v7())->failed('чужой отказ')->persistWith($dayLoads);
        self::assertTrue($this->summary($container, $company)->current->dataComplete);

        // Raw за 10 августа есть, но прогон начислений отказал посреди
        // страниц — записано не всё (ADR-038).
        $mark->failed()->persistWith($dayLoads);

        $summary = $this->summary($container, $company);
        self::assertSame(1, $summary->current->daysWithoutAccruals);
        self::assertFalse($summary->current->dataComplete);
        self::assertNull($summary->changes->ordersAmountBps);

        $mark->completed()->persistWith($dayLoads);

        $summary = $this->summary($container, $company);
        self::assertSame(0, $summary->current->daysWithoutAccruals);
        self::assertTrue($summary->current->dataComplete);
    }

    public function testRevokedKeyDayStaysADayWithoutAccruals(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);
        $this->loaded($container, $company, '2026-07-25');
        /** @var MarketplaceDayLoadRepository $dayLoads */
        $dayLoads = $container->get(MarketplaceDayLoadRepository::class);

        // Отзыв ключа ставит отказ обеим выгрузкам дня. День продаж
        // от этого днём продаж быть не перестаёт, и неполные начисления
        // за него обязаны остаться видны (ADR-038).
        foreach ([MarketplaceReportType::OzonPostingFboList, MarketplaceReportType::OzonAccrualByDay] as $reportType) {
            MarketplaceDayLoadBuilder::aMarketplaceDayLoad()
                ->withCompanyId($company->id())
                ->withMarketplaceAccountId(Uuid::fromString(self::ACCOUNT_ID))
                ->withReportType($reportType)
                ->withPeriod(new \DateTimeImmutable('2026-08-10'))
                ->failed('Подключение отозвано')
                ->persistWith($dayLoads);
        }

        $summary = $this->summary($container, $company);
        self::assertSame(1, $summary->current->daysWithoutAccruals);
        self::assertFalse($summary->current->dataComplete);
    }

    public function testDaysCoverEveryDateOfEachPeriodWithZeros(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->sale($container, $company, '2026-08-10', 'delivered', 1, 12_345);
        $this->accrual($container, $company, '2026-08-02', 32, -700, 'P-1');

        $summary = $this->summary($container, $company);

        self::assertCount(7, $summary->current->days);
        self::assertSame('2026-08-08', $summary->current->days[0]->date);
        self::assertSame('2026-08-14', $summary->current->days[6]->date);
        self::assertSame(0, $summary->current->days[0]->ordersAmountMinor);
        self::assertSame(12_345, $summary->current->days[2]->ordersAmountMinor);
        self::assertCount(7, $summary->comparison->days);
        self::assertSame(700, $summary->comparison->days[1]->deliveryLogisticsMinor);
        self::assertSame('RUB', $summary->currency);
    }

    public function testFactsOfAnotherCompanyAreNotCounted(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);
        $foreign = $this->company($container);
        $this->loaded($container, $foreign, '2026-07-01');

        $this->sale($container, $company, '2026-08-08', 'delivered', 1, 10_000);
        $this->sale($container, $foreign, '2026-08-08', 'delivered', 7, 700_000);
        $this->accrual($container, $foreign, '2026-08-08', OzonFeeTypeNames::REVENUE, 700_000, 'F-1');
        $this->accrual($container, $foreign, '2026-08-08', OzonFeeTypeNames::PAY_PER_CLICK, -9_000, '1');
        $this->accrual($container, $foreign, '2026-08-08', 32, -9_000, 'F-1');

        $summary = $this->summary($container, $company);
        $metrics = $summary->current->metrics;

        self::assertSame(1, $metrics->ordersQuantity);
        self::assertSame(10_000, $metrics->ordersAmountMinor);
        self::assertSame(0, $metrics->salesQuantity);
        self::assertSame(0, $metrics->advertisingMinor);
        self::assertSame(0, $metrics->deliveryLogisticsMinor);
        self::assertSame(1, $metrics->buyoutOrderedQuantity);
        // Загрузки чужой компании не делают её данные «полными».
        self::assertFalse($summary->current->dataComplete);
    }

    public function testBuyoutIsTheBuyoutReportSummaryOfTheSamePeriod(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->sale($container, $company, '2026-08-08', 'delivered', 2, 200_000);
        $this->sale($container, $company, '2026-08-09', 'cancelled', 1, 100_000);

        $metrics = $this->summary($container, $company)->current->metrics;

        /** @var BuildBuyoutRateReportAction $report */
        $report = $container->get(BuildBuyoutRateReportAction::class);
        $expected = ($report)(
            $company->id()->toRfc4122(),
            new \DateTimeImmutable('2026-08-08'),
            new \DateTimeImmutable('2026-08-14'),
            new \DateTimeImmutable('2026-10-15 12:00', new \DateTimeZone('Europe/Moscow')),
            1,
        )->summary;

        self::assertSame(3, $metrics->buyoutOrderedQuantity);
        self::assertSame($expected->orderedQuantity, $metrics->buyoutOrderedQuantity);
        self::assertSame($expected->projectedBuyoutRateBps, $metrics->buyoutRateBps);
        self::assertSame($expected->projectedBuyoutQuantity, $metrics->buyoutQuantity);
        self::assertSame($expected->resolutionRateBps, $metrics->buyoutResolutionRateBps);
    }

    private function summary(ContainerInterface $container, Company $company): DashboardSummary
    {
        /** @var BuildDashboardSummaryAction $action */
        $action = $container->get(BuildDashboardSummaryAction::class);

        return ($action)(
            $company->id()->toRfc4122(),
            new \DateTimeImmutable('2026-08-08'),
            new \DateTimeImmutable('2026-08-14'),
            new \DateTimeImmutable('2026-08-01'),
            new \DateTimeImmutable('2026-08-07'),
            new \DateTimeImmutable('2026-10-15 12:00', new \DateTimeZone('Europe/Moscow')),
        );
    }

    private function sale(ContainerInterface $container, Company $company, string $day, string $status, int $quantity, int $amountMinor): void
    {
        $id = Uuid::v7()->toRfc4122();
        /** @var SalesFactRepository $sales */
        $sales = $container->get(SalesFactRepository::class);

        SalesFactBuilder::aSalesFact()
            ->withCompanyId($company->id())
            ->withMarketplaceAccountId(Uuid::fromString(self::ACCOUNT_ID))
            ->withSourceRowId($id.'|SKU')
            ->withPostingNumber($id)
            ->withOrderNumber($id)
            ->withStatus($status)
            ->withBusinessDate(new \DateTimeImmutable($day))
            ->withQuantity($quantity)
            ->withAmount(Money::ofMinor($amountMinor, 'RUB'))
            ->persistWith($sales);
    }

    private function accrual(ContainerInterface $container, Company $company, string $day, int $feeTypeId, int $amountMinor, string $unitNumber, int $quantity = 1): void
    {
        /** @var MarketplaceExpenseFactRepository $repository */
        $repository = $container->get(MarketplaceExpenseFactRepository::class);

        MarketplaceExpenseFactBuilder::aMarketplaceExpenseFact()
            ->withCompanyId($company->id())
            ->withMarketplaceAccountId(Uuid::fromString(self::ACCOUNT_ID))
            ->withAccrualId($this->accrualId++)
            ->withBusinessDate(new \DateTimeImmutable($day))
            ->withFeeTypeId($feeTypeId)
            ->withUnitNumber($unitNumber)
            ->withAmount(Money::ofMinor($amountMinor, 'RUB'))
            ->withQuantity($quantity)
            ->persistWith($repository);
    }

    /**
     * Обе дневные выгрузки подключения загружены за каждый день с первого
     * по последний, кроме перечисленных пропусков.
     *
     * @param list<string> $gaps
     */
    private function loaded(ContainerInterface $container, Company $company, string $first, string $last = '2026-08-14', string $account = self::ACCOUNT_ID, array $gaps = []): void
    {
        for ($day = new \DateTimeImmutable($first); $day <= new \DateTimeImmutable($last); $day = $day->modify('+1 day')) {
            if (\in_array($day->format('Y-m-d'), $gaps, true)) {
                continue;
            }
            foreach ([MarketplaceReportType::OzonPostingFboList, MarketplaceReportType::OzonAccrualByDay] as $reportType) {
                $this->rawDocument($container, $company, $reportType, $day->format('Y-m-d'), $account);
            }
        }
    }

    private function rawDocument(ContainerInterface $container, Company $company, string $reportType, string $period, string $account = self::ACCOUNT_ID): void
    {
        MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()
            ->withCompanyId($company->id())
            ->withMarketplaceAccountId(Uuid::fromString($account))
            ->withReportType($reportType)
            ->withPeriod(new \DateTimeImmutable($period))
            ->persistWith(MarketplaceRawDocumentBuilder::repository($container));
    }

    private function company(ContainerInterface $container): Company
    {
        /** @var CompanyRepository $companies */
        $companies = $container->get(CompanyRepository::class);

        return CompanyBuilder::aCompany()->persistWith($companies);
    }

    private function bootedContainer(): ContainerInterface
    {
        self::bootKernel();

        return self::getContainer();
    }
}
