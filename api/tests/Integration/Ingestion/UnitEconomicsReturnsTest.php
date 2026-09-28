<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Ingestion\Application\UnitEconomics\BuildUnitEconomicsAction;
use App\Ingestion\Application\UnitEconomics\UnitEconomicsReport;
use App\Ingestion\Domain\MarketplaceExpenseFactRepository;
use App\Ingestion\Domain\OzonFeeTypeNames;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceListingCostRepository;
use App\Ingestion\Infrastructure\Query\UnitEconomics\UnitEconomicsDirection;
use App\Ingestion\Infrastructure\Query\UnitEconomics\UnitEconomicsSort;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceExpenseFactBuilder;
use App\Tests\Support\Builder\MarketplaceListingCostBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Юнит-экономика по методу начисления (ADR-036): продажи и возвраты —
 * строки ленты `by-day` по дате начисления.
 *
 * Обязательное покрытие ADR-005: денежная арифметика и изоляция между
 * компаниями. Суммы — из августовской сверки с кабинетом: возврат
 * 2402 ₽ с возвратом вознаграждения 1104,92 ₽.
 */
final class UnitEconomicsReturnsTest extends KernelTestCase
{
    private const string SKU = '308403988';
    private const string ACCOUNT_ID = '019fe6ea-cd99-7af8-bf4a-623a5a31cf7b';
    private const string POSTING = '46205549-0525-1';

    public function testReturnIsSubtractedFromRevenueAndCommission(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->accrual($container, $company, 1, '2026-08-03', 'other-posting', 240_200, -110_492);
        $this->accrual($container, $company, 2, '2026-08-10', self::POSTING, -240_200, 110_492);

        $sku = $this->report($container, $company)->skus[0];

        // Продажа и возврат одного месяца взаимно гасятся: выручка
        // и комиссия нетто — ноль, но обе штуки видны.
        self::assertSame(1, $sku->deliveredQuantity);
        self::assertSame(1, $sku->returnedQuantity);
        self::assertSame(0, $sku->revenueMinor);
        self::assertSame(-240_200, $sku->returnsMinor);
        self::assertSame(0, $sku->commissionMinor);
        self::assertSame(0, $sku->marginMinor);
    }

    public function testPeriodIsTheAccrualDate(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        // Продажа начислена в июле, возврат — в августе: в августе
        // остаётся только возврат, как в отчёте кабинета.
        $this->accrual($container, $company, 1, '2026-07-28', self::POSTING, 240_200, -110_492);
        $this->accrual($container, $company, 2, '2026-08-10', self::POSTING, -240_200, 110_492);

        $sku = $this->report($container, $company)->skus[0];

        self::assertSame(0, $sku->deliveredQuantity);
        self::assertSame(1, $sku->returnedQuantity);
        self::assertSame(-240_200, $sku->revenueMinor);
        self::assertSame(110_492, $sku->commissionMinor);
    }

    public function testReturnReversesTheCostOfTheOriginalSale(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->cost($container, $company, '2026-07-01', 42_000);
        $this->cost($container, $company, '2026-08-01', 51_000);
        $this->accrual($container, $company, 1, '2026-07-28', self::POSTING, 240_200, -110_492);
        $this->accrual($container, $company, 2, '2026-08-10', self::POSTING, -240_200, 110_492);

        $sku = $this->report($container, $company)->skus[0];

        // Товар продан по июльской цене 420 ₽ — ею и сторнируется.
        // Августовская 510 ₽ дала бы 90 ₽ прибыли, которой не было.
        self::assertSame(42_000, $sku->costTotalMinor);
        self::assertSame(0, $sku->quantityWithoutCost);
    }

    public function testReturnWithoutLoadedSaleTakesThePriceOfItsOwnDay(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);
        $foreign = $this->company($container);

        $this->cost($container, $company, '2026-07-01', 42_000);
        $this->cost($container, $company, '2026-08-01', 51_000);
        // Продажа с тем же отправлением и артикулом есть только у чужой
        // компании. Найди её поиск исходной продажи — сторно пошло бы
        // по июльской цене; своей продажи нет, значит цена дня возврата.
        $this->accrual($container, $foreign, 1, '2026-07-28', self::POSTING, 240_200, -110_492);
        $this->accrual($container, $company, 2, '2026-08-10', self::POSTING, -240_200, 110_492);

        $sku = $this->report($container, $company)->skus[0];

        self::assertSame(51_000, $sku->costTotalMinor);
    }

    public function testSaleRowsAreNotExpenses(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->accrual($container, $company, 1, '2026-08-03', self::POSTING, 274_700, -126_362);
        MarketplaceExpenseFactBuilder::aMarketplaceExpenseFact()
            ->withCompanyId($company->id())
            ->withMarketplaceAccountId(Uuid::fromString(self::ACCOUNT_ID))
            ->withBusinessDate(new \DateTimeImmutable('2026-08-03'))
            ->withMarketplaceSku(self::SKU)
            ->withAccrualId(1)
            ->withUnitNumber(self::POSTING)
            ->withFeeTypeId(32)
            ->withAmount(Money::ofMinor(-6_900, 'RUB'))
            ->persistWith($this->expenseFacts($container));

        $sku = $this->report($container, $company)->skus[0];

        // Выручка и комиссия — в одной таблице с логистикой, но в расходы
        // не попадают: иначе выручка оказалась бы издержкой (ADR-036 п. 7).
        self::assertSame(-6_900, $sku->expensesTotalMinor);
        self::assertCount(1, $sku->expenses);
        self::assertSame(32, $sku->expenses[0]->feeTypeId);
        self::assertSame(274_700 - 126_362 - 6_900, $sku->marginMinor);
    }

    public function testSalesOfAnotherCompanyAreNotCounted(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);
        $foreign = $this->company($container);

        $this->accrual($container, $company, 1, '2026-08-03', self::POSTING, 240_200, -110_492);
        $this->accrual($container, $foreign, 2, '2026-08-10', self::POSTING, -240_200, 110_492);

        $report = $this->report($container, $company);

        self::assertCount(1, $report->skus);
        self::assertSame(0, $report->skus[0]->returnedQuantity);
        self::assertSame(240_200, $report->skus[0]->revenueMinor);
    }

    /**
     * Начисление продажи (выручка > 0) или возврата (выручка < 0):
     * две строки ленты — выручка и вознаграждение за продажу.
     */
    private function accrual(
        ContainerInterface $container,
        Company $company,
        int $accrualId,
        string $day,
        string $posting,
        int $revenueMinor,
        int $commissionMinor,
    ): void {
        $row = MarketplaceExpenseFactBuilder::aMarketplaceExpenseFact()
            ->withCompanyId($company->id())
            ->withMarketplaceAccountId(Uuid::fromString(self::ACCOUNT_ID))
            ->withBusinessDate(new \DateTimeImmutable($day))
            ->withMarketplaceSku(self::SKU)
            ->withAccrualId($accrualId)
            ->withUnitNumber($posting);

        $row->withFeeTypeId(OzonFeeTypeNames::REVENUE)
            ->withAmount(Money::ofMinor($revenueMinor, 'RUB'))
            ->persistWith($this->expenseFacts($container));
        $row->withFeeTypeId(OzonFeeTypeNames::SALE_COMMISSION)
            ->withAmount(Money::ofMinor($commissionMinor, 'RUB'))
            ->persistWith($this->expenseFacts($container));
    }

    private function cost(ContainerInterface $container, Company $company, string $effectiveFrom, int $unitCostMinor): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);

        MarketplaceListingCostBuilder::aMarketplaceListingCost()
            ->withCompanyId($company->id())
            ->withMarketplaceAccountId(Uuid::fromString(self::ACCOUNT_ID))
            ->withMarketplaceSku(self::SKU)
            ->withEffectiveFrom(new \DateTimeImmutable($effectiveFrom))
            ->withUnitCost(Money::ofMinor($unitCostMinor, 'RUB'))
            ->persistWith(new DoctrineMarketplaceListingCostRepository($entityManager));
    }

    private function report(ContainerInterface $container, Company $company): UnitEconomicsReport
    {
        /** @var BuildUnitEconomicsAction $action */
        $action = $container->get(BuildUnitEconomicsAction::class);

        return ($action)(
            $company->id()->toRfc4122(),
            new \DateTimeImmutable('2026-08-01'),
            new \DateTimeImmutable('2026-08-31'),
            50,
            31,
            UnitEconomicsSort::Revenue,
            UnitEconomicsDirection::Desc,
            null,
        );
    }

    private function expenseFacts(ContainerInterface $container): MarketplaceExpenseFactRepository
    {
        /** @var MarketplaceExpenseFactRepository $repository */
        $repository = $container->get(MarketplaceExpenseFactRepository::class);

        return $repository;
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
