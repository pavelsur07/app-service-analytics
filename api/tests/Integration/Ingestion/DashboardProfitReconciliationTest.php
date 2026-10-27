<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Domain\CompanyRepository;
use App\Ingestion\Application\Dashboard\BuildDashboardSummaryAction;
use App\Ingestion\Application\UnitEconomics\BuildUnitEconomicsAction;
use App\Ingestion\Domain\AdSkuExpenseFactRepository;
use App\Ingestion\Domain\MarketplaceExpenseFactRepository;
use App\Ingestion\Domain\MarketplaceListingCost;
use App\Ingestion\Domain\MarketplaceListingCostRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Infrastructure\Query\UnitEconomics\UnitEconomicsDirection;
use App\Ingestion\Infrastructure\Query\UnitEconomics\UnitEconomicsSort;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\AdSkuExpenseFactBuilder;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceExpenseFactBuilder;
use App\Tests\Support\Builder\MarketplaceListingCostBuilder;
use App\Tests\Support\Builder\MarketplaceRawDocumentBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DashboardProfitReconciliationTest extends KernelTestCase
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
        foreach (['2026-08-07', '2026-08-08'] as $date) {
            foreach ([MarketplaceReportType::OzonAccrualByDay, MarketplaceReportType::OzonPostingFboList] as $type) {
                MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()->withCompanyId($this->companyId)
                    ->withMarketplaceAccountId($this->accountId)->withReportType($type)->withPeriod(new \DateTimeImmutable($date))
                    ->persistWith(MarketplaceRawDocumentBuilder::repository(self::getContainer()));
            }
        }
    }

    #[DataProvider('advertisingAmounts')]
    public function testProfitReconcilesWithSkuProfitAndCabinetResidual(int $advertising): void
    {
        $cost = $this->cost();
        foreach ([[0, 100_000], [69, -20_000], [32, -5_000]] as [$type, $amount]) {
            $this->fact('2026-08-08', $type, $amount);
        }
        $this->fact('2026-08-08', 41, -10_000, '');
        /** @var AdSkuExpenseFactRepository $ads */
        $ads = self::getContainer()->get(AdSkuExpenseFactRepository::class);
        AdSkuExpenseFactBuilder::anAdSkuExpenseFact()->withCompanyId($this->companyId)->withMarketplaceAccountId($this->accountId)
            ->withMarketplaceSku('SKU')->withBusinessDate(new \DateTimeImmutable('2026-08-08'))
            ->withAmount(Money::ofMinor($advertising, 'RUB'))->persistWith($ads);
        $this->assertReconciles(35_000);

        $cost->correctTo(Money::ofMinor(40_000, 'RUB'), new \DateTimeImmutable('2026-10-01'));
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->flush();
        $this->assertReconciles(25_000);
    }

    /** @return iterable<string, array{int}> */
    public static function advertisingAmounts(): iterable
    {
        yield 'negative remainder' => [-8_000];
        yield 'positive remainder' => [-12_000];
    }

    public function testReturnReconcilesUsingOriginalSaleOutsideWindow(): void
    {
        $this->cost();
        $this->fact('2026-08-01', 0, 100_000);
        $this->fact('2026-08-08', 0, -100_000);
        $this->fact('2026-08-08', 69, 20_000);
        $this->fact('2026-08-08', 59, -2_000);
        $this->assertReconciles(-52_000);
    }

    private function assertReconciles(int $expected): void
    {
        /** @var BuildDashboardSummaryAction $dashboard */
        $dashboard = self::getContainer()->get(BuildDashboardSummaryAction::class);
        /** @var BuildUnitEconomicsAction $economics */
        $economics = self::getContainer()->get(BuildUnitEconomicsAction::class);
        $day = new \DateTimeImmutable('2026-08-08');
        $previous = new \DateTimeImmutable('2026-08-07');
        $summary = $dashboard($this->companyId->toRfc4122(), $day, $day, $previous, $previous, new \DateTimeImmutable('2026-10-01'));
        $report = $economics($this->companyId->toRfc4122(), $day, $day, 50, 1, UnitEconomicsSort::Revenue, UnitEconomicsDirection::Desc, null);
        self::assertNull($report->nextCursor);
        self::assertCount(1, $report->skus);
        self::assertNotNull($report->skus[0]->profitMinor);
        self::assertSame($expected, $summary->current->metrics->profitMinor);
        self::assertSame($expected, $report->skus[0]->profitMinor + $report->cabinetExpensesTotalMinor);
    }

    private function cost(): MarketplaceListingCost
    {
        /** @var MarketplaceListingCostRepository $costs */
        $costs = self::getContainer()->get(MarketplaceListingCostRepository::class);

        return MarketplaceListingCostBuilder::aMarketplaceListingCost()->withCompanyId($this->companyId)->withMarketplaceAccountId($this->accountId)
            ->withMarketplaceSku('SKU')->withUnitCost(Money::ofMinor(30_000, 'RUB'))->persistWith($costs);
    }

    private function fact(string $day, int $type, int $minor, string $sku = 'SKU'): void
    {
        /** @var MarketplaceExpenseFactRepository $expenses */
        $expenses = self::getContainer()->get(MarketplaceExpenseFactRepository::class);
        MarketplaceExpenseFactBuilder::aMarketplaceExpenseFact()->withCompanyId($this->companyId)->withMarketplaceAccountId($this->accountId)
            ->withAccrualId($this->accrualId++)->withBusinessDate(new \DateTimeImmutable($day))->withFeeTypeId($type)
            ->withMarketplaceSku($sku)->withAmount(Money::ofMinor($minor, 'RUB'))->persistWith($expenses);
    }
}
