<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Ingestion\Application\BuildUnitEconomicsAction;
use App\Ingestion\Application\UnitEconomicsReport;
use App\Ingestion\Domain\MarketplaceExpenseFactRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Query\UnitEconomicsCursor;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceExpenseFactBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Расчёт юнит-экономики: денежная арифметика и сборка отчёта.
 * ADR-005 относит денежную арифметику к обязательному покрытию,
 * а тестировать контроллеры §9 запрещает — поэтому здесь, а не
 * через HTTP.
 */
final class BuildUnitEconomicsActionTest extends KernelTestCase
{
    private const string DAY = '2026-07-01';

    public function testMarginIsRevenuePlusNegativeCommissionAndExpenses(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->sale($container, $company, '111', 274_700, -126_362);
        $this->expense($container, $company, '111', 32, -6_900);
        $this->expense($container, $company, '111', 29, -785);

        $report = $this->build($container, $company);

        self::assertCount(1, $report->skus);
        $sku = $report->skus[0];
        self::assertSame(274_700, $sku->revenueMinor);
        self::assertSame(-126_362, $sku->commissionMinor);
        self::assertSame(-7_685, $sku->expensesTotalMinor);
        // Комиссия и расходы приходят от площадки отрицательными,
        // поэтому итог — сложение. «Взять по модулю» означало бы
        // сложить расход с выручкой и показать прибыль вдвое больше.
        self::assertSame(-134_047, $sku->deductionsTotalMinor);
        self::assertSame(274_700 - 126_362 - 7_685, $sku->marginMinor);
    }

    public function testExpensesAreGroupedByTypeWithTheLargestFirst(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->sale($container, $company, '111', 100_000, -1_000);
        $this->expense($container, $company, '111', 29, -785);
        $this->expense($container, $company, '111', 32, -6_900, accrualId: 2);
        $this->expense($container, $company, '111', 32, -100, accrualId: 3);

        $report = $this->build($container, $company);

        $expenses = $report->skus[0]->expenses;
        self::assertCount(2, $expenses);
        // Одинаковые типы сложены, и первым идёт тот, что съедает
        // больше всего, — ради него экран и открывают.
        self::assertSame(32, $expenses[0]->feeTypeId);
        self::assertSame(-7_000, $expenses[0]->amountMinor);
        self::assertSame('Логистика', $expenses[0]->name);
        self::assertSame(-785, $expenses[1]->amountMinor);
    }

    public function testCabinetExpensesAreApartFromProducts(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->expense($container, $company, '', 41, -23_793);

        $report = $this->build($container, $company);

        // Реклама и хранение не размазываются по товарам (ADR-012):
        // базис распределения захочется менять, а показанная строка
        // честнее доли, происхождение которой клиент не проверит.
        self::assertSame([], $report->skus);
        self::assertSame(-23_793, $report->cabinetExpensesTotalMinor);
        self::assertSame('Оплата за клик', $report->cabinetExpenses[0]->name);
    }

    public function testProductWithExpensesButNoSalesIsIncluded(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        // Возврат обработали в этом периоде, а продан товар был раньше.
        // Спрятать такой расход значило бы занизить издержки.
        $this->expense($container, $company, '222', 59, -11_500);

        $report = $this->build($container, $company);

        self::assertCount(1, $report->skus);
        self::assertSame('222', $report->skus[0]->marketplaceSku);
        self::assertSame(0, $report->skus[0]->revenueMinor);
        self::assertSame(-11_500, $report->skus[0]->marginMinor);
    }

    public function testPageIsLimitedAndCursorLeadsToTheRest(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->sale($container, $company, '111', 300_000, -1_000, 'sale-1');
        $this->sale($container, $company, '222', 200_000, -1_000, 'sale-2');
        $this->sale($container, $company, '333', 100_000, -1_000, 'sale-3');

        $first = $this->build($container, $company, limit: 2);

        // Товар с расходами, но без продаж, объединяется в SQL, а не
        // дописывается в PHP: иначе он проходил бы мимо лимита страницы,
        // и отчёт превышал бы собственный потолок.
        self::assertCount(2, $first->skus);
        self::assertSame(['111', '222'], array_map(
            static fn ($sku): string => $sku->marketplaceSku,
            $first->skus,
        ));
        self::assertNotNull($first->nextCursor);

        $second = $this->build($container, $company, limit: 2, cursor: UnitEconomicsCursor::fromString($first->nextCursor));

        self::assertSame(['333'], array_map(
            static fn ($sku): string => $sku->marketplaceSku,
            $second->skus,
        ));
        self::assertNull($second->nextCursor);
    }

    public function testDataOfAnotherCompanyIsNotCounted(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);
        $foreign = $this->company($container);

        $this->sale($container, $company, '111', 100_000, -1_000);
        $this->sale($container, $foreign, '111', 999_999, -99_999, 'foreign-1');
        $this->expense($container, $foreign, '111', 32, -99_999);

        $report = $this->build($container, $company);

        // Обязательное покрытие ADR-005: артикул один и тот же,
        // разделяет только company_id в самом запросе.
        self::assertCount(1, $report->skus);
        self::assertSame(100_000, $report->skus[0]->revenueMinor);
        self::assertSame(0, $report->skus[0]->expensesTotalMinor);
    }

    private function build(
        ContainerInterface $container,
        Company $company,
        int $limit = 50,
        ?UnitEconomicsCursor $cursor = null,
    ): UnitEconomicsReport {
        /** @var BuildUnitEconomicsAction $action */
        $action = $container->get(BuildUnitEconomicsAction::class);

        return ($action)(
            $company->id()->toRfc4122(),
            new \DateTimeImmutable(self::DAY),
            new \DateTimeImmutable(self::DAY),
            $limit,
            $cursor,
        );
    }

    private function sale(
        ContainerInterface $container,
        Company $company,
        string $sku,
        int $amountMinor,
        int $commissionMinor,
        string $sourceRowId = 'sale',
    ): void {
        /** @var SalesFactRepository $salesFacts */
        $salesFacts = $container->get(SalesFactRepository::class);

        SalesFactBuilder::aSalesFact()
            ->withCompanyId($company->id())
            ->withBusinessDate(new \DateTimeImmutable(self::DAY))
            ->withMarketplaceSku($sku)
            ->withSourceRowId($sourceRowId.'-'.$sku)
            ->withStatus('delivered')
            ->withAmount(Money::ofMinor($amountMinor, 'RUB'))
            ->withCommissionAmount(Money::ofMinor($commissionMinor, 'RUB'))
            ->persistWith($salesFacts);
    }

    private function expense(
        ContainerInterface $container,
        Company $company,
        string $sku,
        int $feeTypeId,
        int $amountMinor,
        int $accrualId = 1,
    ): void {
        /** @var MarketplaceExpenseFactRepository $expenseFacts */
        $expenseFacts = $container->get(MarketplaceExpenseFactRepository::class);

        MarketplaceExpenseFactBuilder::aMarketplaceExpenseFact()
            ->withCompanyId($company->id())
            ->withBusinessDate(new \DateTimeImmutable(self::DAY))
            ->withMarketplaceSku($sku)
            ->withFeeTypeId($feeTypeId)
            ->withAccrualId($accrualId)
            ->withAmount(Money::ofMinor($amountMinor, 'RUB'))
            ->persistWith($expenseFacts);
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
