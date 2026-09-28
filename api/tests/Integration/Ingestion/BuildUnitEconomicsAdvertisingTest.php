<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Ingestion\Application\OzonAdvertisingWindows;
use App\Ingestion\Application\UnitEconomics\BuildUnitEconomicsAction;
use App\Ingestion\Application\UnitEconomics\UnitEconomicsReport;
use App\Ingestion\Domain\AdSkuExpenseFactRepository;
use App\Ingestion\Domain\MarketplaceExpenseFactRepository;
use App\Ingestion\Domain\MarketplaceRawDocumentRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Query\UnitEconomics\UnitEconomicsDirection;
use App\Ingestion\Infrastructure\Query\UnitEconomics\UnitEconomicsSort;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\AdSkuExpenseFactBuilder;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceExpenseFactBuilder;
use App\Tests\Support\Builder\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Реклама в юнит-экономике (ADR-035 п. 5–6): у товара — из SKU-отчётов
 * площадки как есть, в кабинете — остаток «Оплаты за клик» из `by-day`,
 * не разнесённый по товарам; сверка по паре «кампания × день».
 *
 * Денежная арифметика и изоляция компаний — обязательное покрытие
 * (ADR-005), поэтому здесь, через реальный Postgres.
 */
final class BuildUnitEconomicsAdvertisingTest extends KernelTestCase
{
    private const string DAY = '2026-09-23';

    /** @var array<string, Uuid> подключение каждой компании теста */
    private array $accounts = [];

    public function testAdvertisingOfTheSkuIsPartOfItsDeductionsAndMargin(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->sale($container, $company, '286085455', 222_100, -102_166);
        // Один SKU в двух кампаниях — расход складывается.
        $this->adSku($container, $company, '14275771', '286085455', -119_339);
        $this->adSku($container, $company, '16017246', '286085455', -1_000);

        $sku = $this->build($container, $company)->skus[0];

        self::assertSame(-120_339, $sku->advertisingMinor);
        self::assertSame(-102_166 - 120_339, $sku->deductionsTotalMinor);
        self::assertSame(222_100 - 102_166 - 120_339, $sku->marginMinor);
    }

    public function testSkuWithAdvertisingOnlyIsListed(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        // Реклама шла, продаж за период нет — расход не прячется.
        $this->adSku($container, $company, '14275771', '308389906', -3_681);

        $report = $this->build($container, $company);

        self::assertCount(1, $report->skus);
        self::assertSame(['308389906', -3_681, -3_681], [$report->skus[0]->marketplaceSku, $report->skus[0]->advertisingMinor, $report->skus[0]->marginMinor]);
    }

    public function testMarginOrderingCountsAdvertising(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->sale($container, $company, '111', 100_000, 0, 'a');
        $this->sale($container, $company, '222', 90_000, 0, 'b');
        // Без рекламы 111 впереди; реклама переворачивает порядок.
        $this->adSku($container, $company, '14275771', '111', -20_000);

        $report = $this->build($container, $company, sort: UnitEconomicsSort::Margin);

        self::assertSame(['222', '111'], array_map(static fn ($s): string => $s->marketplaceSku, $report->skus));
        // Маржа сортировки (SQL) и показанная (Money) — одна величина.
        self::assertSame(80_000, $report->skus[1]->marginMinor);
    }

    public function testByDayClickPaymentIsReplacedByTheUnallocatedRemainder(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        // Площадка списала по кампании 506,19 ₽; SKU-отчёт разложил
        // 506,20 ₽ — построчное округление, копейка сверху.
        $this->byDay($container, $company, '24147313', -50_619);
        $this->adSku($container, $company, '24147313', '308403988', -30_310);
        $this->adSku($container, $company, '24147313', '308866704', -20_310);
        $this->cabinetExpense($container, $company, 46, -7_945);

        $report = $this->build($container, $company);

        // «Оплата за клик» в кабинете — только остатком, не разнесённым
        // по товарам; хранение остаётся строкой.
        self::assertSame(
            [[46, 'Размещение товаров на складах Ozon', -7_945], [41, 'Реклама, не разнесённая по товарам', 1]],
            array_map(static fn ($e): array => [$e->feeTypeId, $e->name, $e->amountMinor], $report->cabinetExpenses),
        );
        self::assertSame(-7_945 + 1, $report->cabinetExpensesTotalMinor);

        // Инвариант ADR-035 п. 6: реклама по SKU плюс остаток — итог
        // `by-day`; отчёт сходится с финансовым отчётом площадки.
        $skuAdvertising = Money::sum(array_map(static fn ($s): Money => Money::ofMinor($s->advertisingMinor, 'RUB'), $report->skus));
        self::assertSame(-50_619, $skuAdvertising->plus(Money::ofMinor($report->cabinetExpenses[1]->amountMinor, 'RUB'))->minorAmount());
        // Копейка на две строки — в допуске сверки.
        self::assertSame(0, $report->advertisingUnreconciledDays);
    }

    public function testCabinetWithoutAdvertisingKeyKeepsTheWholeClickPaymentAsRemainder(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container, advertising: false);

        $this->byDay($container, $company, '24147313', -50_619);

        $report = $this->build($container, $company);

        // Разбивки нет — «Оплата за клик» строкой кабинета целиком,
        // ровно как до ADR-035 (п. 6).
        self::assertSame(
            [[41, 'Оплата за клик', -50_619]],
            array_map(static fn ($e): array => [$e->feeTypeId, $e->name, $e->amountMinor], $report->cabinetExpenses),
        );
        self::assertSame(-50_619, $report->cabinetExpensesTotalMinor);
        // Кабинет без рекламного ключа не сверяется: иначе каждый его
        // день выглядел бы несошедшимся.
        self::assertSame(0, $report->advertisingUnreconciledDays);
    }

    public function testDifferenceBeyondAKopeckPerSkuRowIsAnUnreconciledDay(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        // Три копейки на две строки — больше допуска.
        $this->byDay($container, $company, '24147313', -50_619);
        $this->adSku($container, $company, '24147313', '308403988', -30_310);
        $this->adSku($container, $company, '24147313', '308866704', -20_312);

        self::assertSame(1, $this->build($container, $company)->advertisingUnreconciledDays);
    }

    public function testMissingSplitAndMissingAccrualAreUnreconciledDays(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        // 23.09: списание есть, разбивки нет. 22.09: разбивка есть,
        // списания нет. Оба дня — несошедшиеся.
        $this->byDay($container, $company, '24147313', -50_619);
        $this->adSku($container, $company, '29088934', '4193185023', -30_673, day: '2026-09-22');

        self::assertSame(2, $this->build($container, $company, from: '2026-09-22')->advertisingUnreconciledDays);
    }

    public function testMissingAccrualIsUnreconciledWhateverTheTolerance(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        // Начисления нет, а разбивка — одна копейка на одну строку:
        // допуск на округление к этому исходу не применяется.
        $this->adSku($container, $company, '29088934', '4193185023', -1);

        self::assertSame(1, $this->build($container, $company)->advertisingUnreconciledDays);
    }

    public function testZeroSkuRowsDoNotWidenTheTolerance(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        // Две копейки расхождения на одну ненулевую строку; нулевые
        // строки не округлялись и допуск не расширяют.
        $this->byDay($container, $company, '24147313', -50_619);
        $this->adSku($container, $company, '24147313', '308403988', -50_617);
        $this->adSku($container, $company, '24147313', '308866704', 0);
        $this->adSku($container, $company, '24147313', '308389906', 0);

        self::assertSame(1, $this->build($container, $company)->advertisingUnreconciledDays);
    }

    public function testTodayIsNotReconciled(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);
        $today = OzonAdvertisingWindows::today(new \DateTimeImmutable())->format('Y-m-d');

        // Начисление за сегодня `by-day` отдаст только завтра.
        $this->adSku($container, $company, '14275771', '286085455', -119_339, day: $today);

        self::assertSame(0, $this->build($container, $company, from: $today, to: $today)->advertisingUnreconciledDays);
    }

    public function testAdvertisingOfAnotherCompanyIsNotCounted(): void
    {
        $container = $this->bootedContainer();
        $ours = $this->company($container);
        // Тот же идентификатор подключения — строже, чем разные: утечка
        // по пропущенному фильтру компании сложилась бы с нашей парой
        // и не могла бы спрятаться за другим подключением.
        $theirs = $this->company($container, account: $this->account($ours));

        $this->byDay($container, $ours, '24147313', -50_619);
        $this->adSku($container, $ours, '24147313', '308403988', -50_619);
        // Те же кампания, день и SKU у другой компании — другие деньги;
        // ещё одна их кампания без разбивки дала бы нам несошедшийся день.
        $this->byDay($container, $theirs, '24147313', -99_999);
        $this->byDay($container, $theirs, '16017246', -12_345);
        $this->adSku($container, $theirs, '24147313', '308403988', -1);
        $this->adSku($container, $theirs, '24147313', '999', -77_777);

        $report = $this->build($container, $ours);

        self::assertSame(['308403988'], array_map(static fn ($s): string => $s->marketplaceSku, $report->skus));
        self::assertSame(-50_619, $report->skus[0]->advertisingMinor);
        self::assertSame([], $report->cabinetExpenses);
        self::assertSame(0, $report->advertisingUnreconciledDays);
    }

    private function build(
        ContainerInterface $container,
        Company $company,
        UnitEconomicsSort $sort = UnitEconomicsSort::Revenue,
        string $from = self::DAY,
        string $to = self::DAY,
    ): UnitEconomicsReport {
        /** @var BuildUnitEconomicsAction $action */
        $action = $container->get(BuildUnitEconomicsAction::class);

        return ($action)(
            $company->id()->toRfc4122(),
            new \DateTimeImmutable($from),
            new \DateTimeImmutable($to),
            50,
            1,
            $sort,
            UnitEconomicsDirection::Desc,
            null,
        );
    }

    private function sale(ContainerInterface $container, Company $company, string $sku, int $amountMinor, int $commissionMinor, string $sourceRowId = 'sale'): void
    {
        /** @var SalesFactRepository $salesFacts */
        $salesFacts = $container->get(SalesFactRepository::class);

        SalesFactBuilder::aSalesFact()
            ->withCompanyId($company->id())
            ->withMarketplaceAccountId($this->account($company))
            ->withBusinessDate(new \DateTimeImmutable(self::DAY))
            ->withMarketplaceSku($sku)
            ->withSourceRowId($sourceRowId.'-'.$sku)
            ->withStatus('delivered')
            ->withAmount(Money::ofMinor($amountMinor, 'RUB'))
            ->withCommissionAmount(Money::ofMinor($commissionMinor, 'RUB'))
            ->persistWith($salesFacts);
    }

    private function adSku(ContainerInterface $container, Company $company, string $campaignId, string $sku, int $amountMinor, string $day = self::DAY): void
    {
        /** @var AdSkuExpenseFactRepository $facts */
        $facts = $container->get(AdSkuExpenseFactRepository::class);

        AdSkuExpenseFactBuilder::anAdSkuExpenseFact()
            ->withCompanyId($company->id())
            ->withMarketplaceAccountId($this->account($company))
            ->withCampaignId($campaignId)
            ->withBusinessDate(new \DateTimeImmutable($day))
            ->withMarketplaceSku($sku)
            ->withAmount(Money::ofMinor($amountMinor, 'RUB'))
            ->persistWith($facts);
    }

    /** «Оплата за клик» кампании за день из `by-day`: unit_number — кампания. */
    private function byDay(ContainerInterface $container, Company $company, string $campaignId, int $amountMinor): void
    {
        /** @var MarketplaceExpenseFactRepository $facts */
        $facts = $container->get(MarketplaceExpenseFactRepository::class);

        MarketplaceExpenseFactBuilder::aMarketplaceExpenseFact()
            ->withCompanyId($company->id())
            ->withMarketplaceAccountId($this->account($company))
            ->withAccrualId((int) $campaignId)
            ->withBusinessDate(new \DateTimeImmutable(self::DAY))
            ->withoutSku()
            ->withFeeTypeId(41)
            ->withUnitNumber($campaignId)
            ->withAmount(Money::ofMinor($amountMinor, 'RUB'))
            ->persistWith($facts);
    }

    private function cabinetExpense(ContainerInterface $container, Company $company, int $feeTypeId, int $amountMinor): void
    {
        /** @var MarketplaceExpenseFactRepository $facts */
        $facts = $container->get(MarketplaceExpenseFactRepository::class);

        MarketplaceExpenseFactBuilder::aMarketplaceExpenseFact()
            ->withCompanyId($company->id())
            ->withMarketplaceAccountId($this->account($company))
            ->withBusinessDate(new \DateTimeImmutable(self::DAY))
            ->withoutSku()
            ->withFeeTypeId($feeTypeId)
            ->withAmount(Money::ofMinor($amountMinor, 'RUB'))
            ->persistWith($facts);
    }

    /**
     * Компания с подключением. Реклама подключена — значит, её расход
     * загружался: в raw есть `ozon_ad_expense`, по нему сверка и знает,
     * какие подключения сверять.
     */
    private function account(Company $company): Uuid
    {
        return $this->accounts[$company->id()->toRfc4122()] ?? throw new \LogicException('Компания теста без подключения.');
    }

    private function company(ContainerInterface $container, bool $advertising = true, ?Uuid $account = null): Company
    {
        /** @var CompanyRepository $companies */
        $companies = $container->get(CompanyRepository::class);
        $company = CompanyBuilder::aCompany()->persistWith($companies);
        $this->accounts[$company->id()->toRfc4122()] = $account ?? Uuid::v7();

        if ($advertising) {
            /** @var MarketplaceRawDocumentRepository $rawDocuments */
            $rawDocuments = $container->get(MarketplaceRawDocumentRepository::class);
            MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()
                ->withCompanyId($company->id())
                ->withMarketplaceAccountId($this->account($company))
                ->withReportType(MarketplaceReportType::OzonAdExpense)
                ->withPeriod(new \DateTimeImmutable(self::DAY))
                ->persistWith($rawDocuments);
        }

        return $company;
    }

    private function bootedContainer(): ContainerInterface
    {
        self::bootKernel();

        return self::getContainer();
    }
}
