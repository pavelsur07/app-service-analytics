<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Domain\CompanyRepository;
use App\Ingestion\Domain\MarketplaceExpenseFactRepository;
use App\Ingestion\Domain\MarketplaceListingCostRepository;
use App\Ingestion\Infrastructure\Query\Dashboard\DashboardProfitQuery;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceExpenseFactBuilder;
use App\Tests\Support\Builder\MarketplaceListingCostBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DashboardProfitQueryTest extends KernelTestCase
{
    private Uuid $companyId;
    private Uuid $accountId;
    private int $accrualId = 1;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var CompanyRepository $companies */
        $companies = self::getContainer()->get(CompanyRepository::class);
        $this->companyId = CompanyBuilder::aCompany()->persistWith($companies)->id();
        $this->accountId = Uuid::v7();
    }

    public function testAllAccrualsIncludeCabinetExpensesAndPositiveCorrectionsOnce(): void
    {
        $this->cost('2026-07-01', 30_000);
        $this->fact('2026-08-31', 0, 100_000);
        $this->fact('2026-08-31', 69, -20_000);
        $this->fact('2026-08-31', 32, -5_000);
        $this->fact('2026-08-31', 41, -10_000, sku: '');
        $this->fact('2026-08-31', 99, 700, sku: '');
        $row = $this->totals()[0];
        self::assertSame(65_700, $row->accrualsTotalMinor);
        self::assertSame(-30_000, $row->costTotalMinor);
        self::assertSame(0, $row->quantityWithoutCost);
    }

    public function testReturnUsesOriginalSaleCostOutsideRequestedPeriods(): void
    {
        $this->cost('2026-07-01', 30_000);
        $this->cost('2026-09-01', 40_000);
        $this->fact('2026-08-10', 0, 200_000, quantity: 2);
        $this->fact('2026-09-03', 0, -200_000, quantity: 2);
        $row = $this->totals()[0];
        self::assertTrue($row->current);
        self::assertSame(60_000, $row->costTotalMinor);
        self::assertSame(0, $row->quantityWithoutCost);
    }

    public function testEachRevenueRowUsesItsHistoricalPriceAndQuantity(): void
    {
        $this->cost('2026-07-01', 30_000);
        $this->cost('2026-09-01', 40_000);
        $this->fact('2026-08-31', 0, 200_000, quantity: 2);
        $this->fact('2026-09-03', 0, 100_000, posting: 'P-2');
        $rows = $this->totals();
        self::assertSame(-60_000, $rows[0]->costTotalMinor);
        self::assertFalse($rows[0]->current);
        self::assertSame(-40_000, $rows[1]->costTotalMinor);
        self::assertTrue($rows[1]->current);
    }

    public function testReturnWithoutOriginalSaleUsesReturnDate(): void
    {
        $this->cost('2026-09-01', 40_000);
        $this->fact('2026-09-03', 0, -100_000);
        self::assertSame(40_000, $this->totals()[0]->costTotalMinor);
    }

    public function testEmptyPostingDoesNotBorrowAnotherSaleDate(): void
    {
        $this->cost('2026-07-01', 30_000);
        $this->cost('2026-09-01', 40_000);
        $this->fact('2026-08-10', 0, 100_000, posting: '');
        $this->fact('2026-09-03', 0, -100_000, posting: '');
        self::assertSame(40_000, $this->totals()[0]->costTotalMinor);
    }

    public function testMissingPricesOfSalesAndReturnsDoNotCancelEachOther(): void
    {
        $this->fact('2026-09-02', 0, 200_000, quantity: 2);
        $this->fact('2026-09-03', 0, -200_000, quantity: 2);
        $row = $this->totals()[0];
        self::assertSame(4, $row->quantityWithoutCost);
        self::assertSame(0, $row->costTotalMinor);
        self::assertSame(0, $row->accrualsTotalMinor);
    }

    public function testZeroCostIsKnownAndNonRevenueNeverRequiresAPrice(): void
    {
        $this->cost('2026-07-01', 0);
        $this->fact('2026-09-03', 0, 100_000);
        $this->fact('2026-09-03', 32, -5_000, sku: 'unknown');
        self::assertSame(0, $this->totals()[0]->quantityWithoutCost);
    }

    public function testOtherCompanyCannotProvideCostOrRevenue(): void
    {
        /** @var CompanyRepository $companies */
        $companies = self::getContainer()->get(CompanyRepository::class);
        $other = CompanyBuilder::aCompany()->persistWith($companies)->id();
        $this->cost('2026-07-01', 30_000, companyId: $other);
        $this->fact('2026-08-10', 0, 100_000, companyId: $other);
        $this->fact('2026-09-03', 0, -100_000);
        $row = $this->totals()[0];
        self::assertSame(-100_000, $row->accrualsTotalMinor);
        self::assertSame(1, $row->quantityWithoutCost);
    }

    public function testOtherAccountCannotProvideCostOrOriginalSale(): void
    {
        $other = Uuid::v7();
        $this->cost('2026-07-01', 30_000, accountId: $other);
        $this->cost('2026-09-01', 40_000);
        $this->fact('2026-08-10', 0, 100_000, accountId: $other);
        $this->fact('2026-09-03', 0, -100_000);
        self::assertSame(40_000, $this->totals()[0]->costTotalMinor);
    }

    public function testLatestPriceCurrencyMismatchIsReportedWithoutFallingBack(): void
    {
        $this->cost('2026-07-01', 30_000);
        $this->cost('2026-09-01', 40_000, currency: 'EUR');
        $this->fact('2026-09-03', 0, 200_000, quantity: 2);
        self::assertSame(2, $this->totals()[0]->costCurrencyMismatchQuantity);
    }

    public function testCurrenciesRemainSeparateAndBoundsAreInclusive(): void
    {
        $this->fact('2026-08-30', 32, -999);
        $this->fact('2026-08-31', 32, -100);
        $this->fact('2026-09-01', 32, -200, currency: 'EUR');
        $this->fact('2026-09-03', 32, -300);
        $this->fact('2026-09-04', 32, -999);
        $rows = $this->totals();
        self::assertSame(['RUB', 'EUR', 'RUB'], array_column($rows, 'currency'));
        self::assertSame([-100, -200, -300], array_column($rows, 'accrualsTotalMinor'));
        $q = $this->query()->daily($this->companyId->toRfc4122(), ...$this->dates());
        $days = array_map(DashboardProfitQuery::mapDailyRow(...), $this->fetch($q));
        self::assertSame(['2026-08-31', '2026-09-01', '2026-09-03'], array_column($days, 'date'));
    }

    public function testCostArithmeticExceeds32BitsWithoutRounding(): void
    {
        $this->cost('2026-07-01', 3_000_000_001);
        $this->fact('2026-09-03', 0, 9_000_000_003, quantity: 3);
        self::assertSame(-9_000_000_003, $this->totals()[0]->costTotalMinor);
    }

    public function testCorrectedCostIsReported(): void
    {
        $cost = $this->cost('2026-07-01', 30_000);
        $cost->correctTo(Money::ofMinor(40_000, 'RUB'), new \DateTimeImmutable('2026-10-01T12:00:00+00:00'));
        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $em->flush();
        $this->fact('2026-09-03', 0, 100_000);
        $row = $this->totals()[0];
        self::assertSame(-40_000, $row->costTotalMinor);
        self::assertNotNull($row->costCorrectedAt);
    }

    public function testOverflowIsRejectedInsteadOfSilentlySaturating(): void
    {
        $this->cost('2026-07-01', 5_000_000_000_000_000_000);
        $this->fact('2026-09-03', 0, 100_000, quantity: 2);
        $this->expectException(\UnexpectedValueException::class);
        $this->totals();
    }

    private function query(): DashboardProfitQuery
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return new DashboardProfitQuery($connection);
    }

    /** @return list<\DateTimeImmutable> */
    private function dates(): array
    {
        return array_map(static fn (string $s): \DateTimeImmutable => new \DateTimeImmutable($s), ['2026-09-01', '2026-09-03', '2026-08-31', '2026-08-31']);
    }

    /** @return list<\App\Ingestion\Infrastructure\Query\Dashboard\DashboardProfitTotalsRow> */
    private function totals(): array
    {
        return array_map(DashboardProfitQuery::mapTotalsRow(...), $this->fetch($this->query()->totals($this->companyId->toRfc4122(), ...$this->dates())));
    }

    /** @return list<array<string, mixed>> */
    private function fetch(QueryBuilder $query): array
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection->fetchAllAssociative($query->getSQL(), $query->getParameters(), $query->getParameterTypes());
    }

    private function fact(string $day, int $type, int $amount, int $quantity = 1, string $sku = 'SKU', string $posting = 'P-1', ?Uuid $companyId = null, ?Uuid $accountId = null, string $currency = 'RUB'): void
    {
        /** @var MarketplaceExpenseFactRepository $repository */
        $repository = self::getContainer()->get(MarketplaceExpenseFactRepository::class);
        MarketplaceExpenseFactBuilder::aMarketplaceExpenseFact()
            ->withCompanyId($companyId ?? $this->companyId)->withMarketplaceAccountId($accountId ?? $this->accountId)
            ->withAccrualId($this->accrualId++)->withBusinessDate(new \DateTimeImmutable($day))
            ->withMarketplaceSku($sku)->withUnitNumber($posting)->withFeeTypeId($type)
            ->withAmount(Money::ofMinor($amount, $currency))->withQuantity($quantity)
            ->persistWith($repository);
    }

    private function cost(string $from, int $minor, ?Uuid $companyId = null, ?Uuid $accountId = null, string $currency = 'RUB'): \App\Ingestion\Domain\MarketplaceListingCost
    {
        /** @var MarketplaceListingCostRepository $repository */
        $repository = self::getContainer()->get(MarketplaceListingCostRepository::class);

        return MarketplaceListingCostBuilder::aMarketplaceListingCost()
            ->withCompanyId($companyId ?? $this->companyId)->withMarketplaceAccountId($accountId ?? $this->accountId)
            ->withMarketplaceSku('SKU')->withEffectiveFrom(new \DateTimeImmutable($from))
            ->withUnitCost(Money::ofMinor($minor, $currency))
            ->persistWith($repository);
    }
}
