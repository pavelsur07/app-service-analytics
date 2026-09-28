<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Ingestion\Application\UnitEconomics\AccrualReconciliation;
use App\Ingestion\Application\UnitEconomics\AccrualReconciliationGroup;
use App\Ingestion\Application\UnitEconomics\BuildAccrualReconciliationAction;
use App\Ingestion\Domain\MarketplaceExpenseFactRepository;
use App\Ingestion\Domain\OzonFeeTypeNames;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceExpenseFactBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Сверка с кабинетом: начисления по группам отчёта «Начисления».
 * Обязательное покрытие ADR-005 — денежная арифметика итогов и изоляция
 * между компаниями. Суммы — из августовской выгрузки кабинета.
 */
final class BuildAccrualReconciliationActionTest extends KernelTestCase
{
    public function testAccrualsAreGroupedAsInTheCabinetAndAddUpToTheTotal(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->row($container, $company, 1, OzonFeeTypeNames::REVENUE, 274_700);
        $this->row($container, $company, 1, OzonFeeTypeNames::SALE_COMMISSION, -126_362);
        $this->row($container, $company, 1, 32, -6_900);
        $this->row($container, $company, 2, OzonFeeTypeNames::REVENUE, -240_200);
        $this->row($container, $company, 2, OzonFeeTypeNames::SALE_COMMISSION, 110_492);
        $this->row($container, $company, 3, 25, 297_401, sku: '');

        $report = $this->report($container, $company);

        self::assertSame('RUB', $report->currency);
        self::assertSame(
            ['sales' => 274_700, 'returns' => -240_200, 'commission' => -15_870, 'delivery' => -6_900, 'compensations' => 297_401],
            $this->totals($report),
        );
        // Итог к начислению — сумма всех строк ленты, то число, что
        // клиент видит в выгрузке кабинета.
        self::assertSame(274_700 - 240_200 - 15_870 - 6_900 + 297_401, $report->totalMinor);
        self::assertSame('Возврат выручки', $this->group($report, 'returns')->items[0]->name);
    }

    public function testEachItemSplitsAccruedAndReversed(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        // Комиссия продажи и её возврат, эквайринг и его возврат —
        // строки одного типа с обратным знаком. Компенсация — доход,
        // у неё «начислено» положительное.
        $this->row($container, $company, 1, OzonFeeTypeNames::SALE_COMMISSION, -139_636_960);
        $this->row($container, $company, 2, OzonFeeTypeNames::SALE_COMMISSION, 12_161_262);
        $this->row($container, $company, 3, 1, -2_229_408);
        $this->row($container, $company, 4, 1, 300_720);
        $this->row($container, $company, 5, 25, 1_196_094, sku: '');
        $this->row($container, $company, 6, 32, -6_900);

        $report = $this->report($container, $company);

        $commission = $this->group($report, 'commission')->items[0];
        self::assertSame([-139_636_960, 12_161_262, -127_475_698], [$commission->accruedMinor, $commission->reversedMinor, $commission->amountMinor]);

        $acquiring = $this->group($report, 'partners')->items[0];
        self::assertSame([-2_229_408, 300_720, -1_928_688], [$acquiring->accruedMinor, $acquiring->reversedMinor, $acquiring->amountMinor]);

        $compensation = $this->group($report, 'compensations')->items[0];
        self::assertSame([1_196_094, 0], [$compensation->accruedMinor, $compensation->reversedMinor]);

        // Без возвратов «возвращено» — ноль, а не отсутствие.
        self::assertSame(0, $this->group($report, 'delivery')->items[0]->reversedMinor);
    }

    public function testUnmappedTypeIsShownUngrouped(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->row($container, $company, 1, 118, -50_000, sku: '');

        $report = $this->report($container, $company);

        self::assertSame(['ungrouped' => -50_000], $this->totals($report));
        self::assertSame(118, $this->group($report, 'ungrouped')->items[0]->feeTypeId);
    }

    public function testPeriodIsTheAccrualDate(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->row($container, $company, 1, 32, -6_900, day: '2026-07-31');
        $this->row($container, $company, 2, 32, -7_000, day: '2026-08-01');

        self::assertSame(-7_000, $this->report($container, $company)->totalMinor);
    }

    public function testAccrualsOfAnotherCompanyAreNotCounted(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);
        $foreign = $this->company($container);

        $this->row($container, $company, 1, 32, -6_900);
        $this->row($container, $foreign, 2, OzonFeeTypeNames::REVENUE, 999_900);

        $report = $this->report($container, $company);

        self::assertSame(['delivery' => -6_900], $this->totals($report));
        self::assertSame(-6_900, $report->totalMinor);
    }

    public function testEmptyPeriodHasNoCurrency(): void
    {
        $container = $this->bootedContainer();

        $report = $this->report($container, $this->company($container));

        // Валюту пустого периода не выбираем за клиента (ADR-004).
        self::assertNull($report->currency);
        self::assertSame([], $report->groups);
        self::assertSame(0, $report->totalMinor);
    }

    /**
     * @return array<string, int>
     */
    private function totals(AccrualReconciliation $report): array
    {
        $totals = [];
        foreach ($report->groups as $group) {
            $totals[$group->code] = $group->totalMinor;
        }

        return $totals;
    }

    private function group(AccrualReconciliation $report, string $code): AccrualReconciliationGroup
    {
        foreach ($report->groups as $group) {
            if ($code === $group->code) {
                return $group;
            }
        }

        self::fail("Группы {$code} нет в отчёте.");
    }

    private function row(
        ContainerInterface $container,
        Company $company,
        int $accrualId,
        int $feeTypeId,
        int $amountMinor,
        string $sku = '308403988',
        string $day = '2026-08-10',
    ): void {
        /** @var MarketplaceExpenseFactRepository $facts */
        $facts = $container->get(MarketplaceExpenseFactRepository::class);

        MarketplaceExpenseFactBuilder::aMarketplaceExpenseFact()
            ->withCompanyId($company->id())
            ->withBusinessDate(new \DateTimeImmutable($day))
            ->withMarketplaceSku($sku)
            ->withAccrualId($accrualId)
            ->withFeeTypeId($feeTypeId)
            ->withAmount(Money::ofMinor($amountMinor, 'RUB'))
            ->persistWith($facts);
    }

    private function report(ContainerInterface $container, Company $company): AccrualReconciliation
    {
        /** @var BuildAccrualReconciliationAction $action */
        $action = $container->get(BuildAccrualReconciliationAction::class);

        return ($action)(
            $company->id()->toRfc4122(),
            new \DateTimeImmutable('2026-08-01'),
            new \DateTimeImmutable('2026-08-31'),
        );
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
