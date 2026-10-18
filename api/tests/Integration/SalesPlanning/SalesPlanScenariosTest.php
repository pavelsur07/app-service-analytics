<?php

declare(strict_types=1);

namespace App\Tests\Integration\SalesPlanning;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Ingestion\Domain\MarketplaceListingRepository;
use App\SalesPlanning\Application\ChangeSalesPlanDayAction;
use App\SalesPlanning\Application\CreateSalesPlanAction;
use App\SalesPlanning\Application\EditSalesPlanOutcome;
use App\SalesPlanning\Application\EditSalesPlanResult;
use App\SalesPlanning\Application\ListSalesPlansAction;
use App\SalesPlanning\Application\RemoveSalesPlanSkuAction;
use App\SalesPlanning\Application\SetSalesPlanSkusAction;
use App\SalesPlanning\Application\SetSalesPlanSkusOutcome;
use App\SalesPlanning\Application\ShowSalesPlanAction;
use App\SalesPlanning\Application\SkuPeriodInput;
use App\SalesPlanning\Domain\SalesPlan;
use App\SalesPlanning\Domain\SalesPlanAuditAction;
use App\SalesPlanning\Domain\SkuPeriodPlan;
use App\SalesPlanning\Infrastructure\Query\SalesPlanGrouping;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\MarketplaceListingBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Сценарии плана продаж этапа 0.4 (ADR-040): создание, ввод SKU
 * за период, чтение — на настоящей PostgreSQL.
 */
final class SalesPlanScenariosTest extends KernelTestCase
{
    private const string Actor = '01900000-0000-7000-8000-000000000001';

    public function testCreatesDraftOnlyForUsableConnectionOfTheCompany(): void
    {
        self::bootKernel();
        $company = $this->company();
        $account = $this->account($company);
        $foreignAccount = $this->account($this->company());
        $revoked = $this->account($company, MarketplaceAccountState::Revoked);

        $plan = $this->create($company, $account);

        self::assertNotNull($plan);
        self::assertSame(1, $plan->version());
        self::assertNull($this->create($company, $foreignAccount));
        self::assertNull($this->create($company, $revoked));
        self::assertSame(1, $this->auditCount($plan, SalesPlanAuditAction::Created));
    }

    public function testSpreadsNewSkusAndAuditsExactDays(): void
    {
        self::bootKernel();
        [$company, $plan] = $this->planWithCatalog(['101', '102'], '2026-07-01', '2026-07-03');

        $versionBefore = $plan->version();

        $result = $this->setSkus($company, $plan, $versionBefore, [
            new SkuPeriodInput('101', 10, 129_900),
            new SkuPeriodInput('102', 2, 50_000),
        ]);

        self::assertSame(SetSalesPlanSkusOutcome::Saved, $result->outcome);
        self::assertSame($versionBefore + 1, $result->version);
        self::assertSame([
            ['101', '2026-07-01', 4, 129_900],
            ['101', '2026-07-02', 3, 129_900],
            ['101', '2026-07-03', 3, 129_900],
            ['102', '2026-07-01', 1, 50_000],
            ['102', '2026-07-02', 1, 50_000],
            ['102', '2026-07-03', 0, 50_000],
        ], $this->lines($plan));

        $audit = $this->audit($plan, SalesPlanAuditAction::SkuSet);
        self::assertCount(2, $audit);
        self::assertNull($audit[0]['previous_value']);
        self::assertSame(
            ['marketplaceSku' => '101', 'currency' => 'RUB', 'days' => [['2026-07-01', 4, 129_900], ['2026-07-02', 3, 129_900], ['2026-07-03', 3, 129_900]]],
            json_decode((string) $audit[0]['new_value'], true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function testRespreadsExistingSkuInPlaceAndAuditsPreviousDays(): void
    {
        self::bootKernel();
        [$company, $plan] = $this->planWithCatalog(['101'], '2026-07-01', '2026-07-03');
        $first = $this->setSkus($company, $plan, $plan->version(), [new SkuPeriodInput('101', 10, 129_900)]);
        $idsBefore = $this->lineIds($plan);

        $second = $this->setSkus($company, $plan, (int) $first->version, [new SkuPeriodInput('101', 5, 99_900)]);

        self::assertSame(SetSalesPlanSkusOutcome::Saved, $second->outcome);
        self::assertSame($idsBefore, $this->lineIds($plan), 'строки правятся на месте, а не заменяются');
        self::assertSame([
            ['101', '2026-07-01', 2, 99_900],
            ['101', '2026-07-02', 2, 99_900],
            ['101', '2026-07-03', 1, 99_900],
        ], $this->lines($plan));

        $audit = $this->audit($plan, SalesPlanAuditAction::SkuSet);
        $previous = json_decode((string) $audit[1]['previous_value'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($previous);
        self::assertSame(
            [['2026-07-01', 4, 129_900], ['2026-07-02', 3, 129_900], ['2026-07-03', 3, 129_900]],
            $previous['days'],
        );
    }

    public function testRefusesSkusMissingFromAccountCatalog(): void
    {
        self::bootKernel();
        [$company, $plan] = $this->planWithCatalog(['101'], '2026-07-01', '2026-07-03');

        $result = $this->setSkus($company, $plan, $plan->version(), [
            new SkuPeriodInput('101', 1, 100),
            new SkuPeriodInput('999', 1, 100),
            new SkuPeriodInput('555', 1, 100),
        ]);

        self::assertSame(SetSalesPlanSkusOutcome::UnknownSkus, $result->outcome);
        self::assertSame(['555', '999'], $result->unknownSkus);
        self::assertSame([], $this->lines($plan));
    }

    public function testStaleVersionIsAConflictAndChangesNothing(): void
    {
        self::bootKernel();
        [$company, $plan] = $this->planWithCatalog(['101'], '2026-07-01', '2026-07-03');
        $stale = $plan->version();
        $this->setSkus($company, $plan, $stale, [new SkuPeriodInput('101', 3, 100)]);

        $result = $this->setSkus($company, $plan, $stale, [new SkuPeriodInput('101', 300, 100)]);

        self::assertSame(SetSalesPlanSkusOutcome::VersionConflict, $result->outcome);
        self::assertSame(3, array_sum(array_column($this->lines($plan), 2)));
    }

    public function testRefusesPlanOverTheSkuLimit(): void
    {
        self::bootKernel();
        [$company, $plan] = $this->planWithCatalog(['new'], '2026-07-01', '2026-07-01');
        $this->bulkLines($plan, SalesPlan::MaxSkus);

        $result = $this->setSkus($company, $plan, $plan->version(), [new SkuPeriodInput('new', 1, 100)]);

        self::assertSame(SetSalesPlanSkusOutcome::TooManySkus, $result->outcome);
    }

    public function testChangesOneDayAndAuditsItsExactValues(): void
    {
        self::bootKernel();
        [$company, $plan] = $this->planWithCatalog(['101'], '2026-07-01', '2026-07-03');
        $saved = $this->setSkus($company, $plan, $plan->version(), [new SkuPeriodInput('101', 10, 129_900)]);

        $result = $this->changeDay($company, $plan, (int) $saved->version, '101', '2026-07-02', 7, 99_900);

        self::assertSame(EditSalesPlanOutcome::Saved, $result->outcome);
        self::assertSame((int) $saved->version + 1, $result->version);
        self::assertSame([
            ['101', '2026-07-01', 4, 129_900],
            ['101', '2026-07-02', 7, 99_900],
            ['101', '2026-07-03', 3, 129_900],
        ], $this->lines($plan));

        $audit = $this->audit($plan, SalesPlanAuditAction::DayChanged);
        self::assertCount(1, $audit);
        self::assertSame(
            ['marketplaceSku' => '101', 'currency' => 'RUB', 'days' => [['2026-07-02', 3, 129_900]]],
            json_decode((string) $audit[0]['previous_value'], true, flags: \JSON_THROW_ON_ERROR),
        );
        self::assertSame(
            ['marketplaceSku' => '101', 'currency' => 'RUB', 'days' => [['2026-07-02', 7, 99_900]]],
            json_decode((string) $audit[0]['new_value'], true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function testDayChangeRefusesWhatIsNotInThePlan(): void
    {
        self::bootKernel();
        [$company, $plan] = $this->planWithCatalog(['101', '102'], '2026-07-01', '2026-07-03');
        $version = (int) $this->setSkus($company, $plan, $plan->version(), [new SkuPeriodInput('101', 3, 100)])->version;

        self::assertSame(EditSalesPlanOutcome::DayOutOfPeriod, $this->changeDay($company, $plan, $version, '101', '2026-07-04', 1, 100)->outcome);
        self::assertSame(EditSalesPlanOutcome::SkuNotInPlan, $this->changeDay($company, $plan, $version, '102', '2026-07-02', 1, 100)->outcome);
        self::assertSame(EditSalesPlanOutcome::VersionConflict, $this->changeDay($company, $plan, $version - 1, '101', '2026-07-02', 1, 100)->outcome);
        self::assertSame(3, array_sum(array_column($this->lines($plan), 2)));
    }

    public function testDayChangeKeepsSkuTotalUnderTheCeiling(): void
    {
        self::bootKernel();
        [$company, $plan] = $this->planWithCatalog(['101'], '2026-07-01', '2026-07-02');
        $version = (int) $this->setSkus($company, $plan, $plan->version(), [new SkuPeriodInput('101', SkuPeriodPlan::MaxTotalQuantity, 100)])->version;

        $result = $this->changeDay($company, $plan, $version, '101', '2026-07-01', SkuPeriodPlan::MaxTotalQuantity, 100);

        self::assertSame(EditSalesPlanOutcome::TotalTooLarge, $result->outcome);
    }

    public function testRemovesSkuAndKeepsItsDaysInTheAudit(): void
    {
        self::bootKernel();
        [$company, $plan] = $this->planWithCatalog(['101', '102'], '2026-07-01', '2026-07-02');
        $version = (int) $this->setSkus($company, $plan, $plan->version(), [
            new SkuPeriodInput('101', 2, 100),
            new SkuPeriodInput('102', 4, 200),
        ])->version;

        $result = $this->service(RemoveSalesPlanSkuAction::class)(
            $company->id()->toRfc4122(),
            $plan->id()->toRfc4122(),
            $version,
            '101',
            self::Actor,
        );

        self::assertSame(EditSalesPlanOutcome::Saved, $result->outcome);
        self::assertSame([['102', '2026-07-01', 2, 200], ['102', '2026-07-02', 2, 200]], $this->lines($plan));
        $audit = $this->audit($plan, SalesPlanAuditAction::SkuRemoved);
        self::assertNull($audit[0]['new_value']);
        $previous = json_decode((string) $audit[0]['previous_value'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($previous);
        self::assertSame([['2026-07-01', 1, 100], ['2026-07-02', 1, 100]], $previous['days']);
    }

    public function testEditsDoNotReachAnotherCompanysPlan(): void
    {
        self::bootKernel();
        [$company, $plan] = $this->planWithCatalog(['101'], '2026-07-01', '2026-07-02');
        $version = (int) $this->setSkus($company, $plan, $plan->version(), [new SkuPeriodInput('101', 2, 100)])->version;
        $stranger = $this->company();

        self::assertSame(EditSalesPlanOutcome::NotFound, $this->changeDay($stranger, $plan, $version, '101', '2026-07-01', 9, 100)->outcome);
        self::assertSame(
            EditSalesPlanOutcome::NotFound,
            $this->service(RemoveSalesPlanSkuAction::class)($stranger->id()->toRfc4122(), $plan->id()->toRfc4122(), $version, '101', self::Actor)->outcome,
        );
        self::assertSame(2, array_sum(array_column($this->lines($plan), 2)));
    }

    public function testShowsTotalsAndBucketsClippedToThePeriod(): void
    {
        self::bootKernel();
        // Среда 2026-07-29 — воскресенье 2026-08-09: две неполные недели
        // на краях, два неполных месяца.
        [$company, $plan] = $this->planWithCatalog(['101', '102'], '2026-07-29', '2026-08-09');
        $saved = $this->setSkus($company, $plan, $plan->version(), [
            new SkuPeriodInput('101', 12, 1_000),
            new SkuPeriodInput('102', 24, 500),
        ]);
        $show = $this->service(ShowSalesPlanAction::class);
        $companyId = $company->id()->toRfc4122();
        $planId = $plan->id()->toRfc4122();

        $period = $show($companyId, $planId, SalesPlanGrouping::Period, null, 50);
        self::assertNotNull($period);
        self::assertSame($saved->version, $period->plan->version);
        self::assertSame(2, $period->plan->skuCount);
        self::assertSame(36, $period->plan->totalQuantity);
        self::assertSame(24_000, $period->plan->revenueMinor);
        self::assertSame([], $period->buckets);

        $weeks = $show($companyId, $planId, SalesPlanGrouping::Week, null, 50);
        self::assertNotNull($weeks);
        self::assertSame(
            [['2026-07-29', '2026-08-02', 5, 5_000], ['2026-08-03', '2026-08-09', 7, 7_000]],
            array_map(static fn ($b): array => [$b->dateFrom, $b->dateTo, $b->quantity, $b->revenueMinor], $weeks->buckets['101'] ?? []),
        );

        $months = $show($companyId, $planId, SalesPlanGrouping::Month, null, 50);
        self::assertNotNull($months);
        self::assertSame(
            [['2026-07-29', '2026-07-31', 3], ['2026-08-01', '2026-08-09', 9]],
            array_map(static fn ($b): array => [$b->dateFrom, $b->dateTo, $b->quantity], $months->buckets['101'] ?? []),
        );

        $days = $show($companyId, $planId, SalesPlanGrouping::Day, null, 1);
        self::assertNotNull($days);
        self::assertCount(1, $days->skus);
        self::assertSame('101', $days->nextCursor);
        self::assertCount(12, $days->buckets['101'] ?? []);

        $next = $show($companyId, $planId, SalesPlanGrouping::Day, $days->nextCursor, 1);
        self::assertNotNull($next);
        self::assertSame('102', $next->skus[0]->marketplaceSku);
        self::assertNull($next->nextCursor);
    }

    public function testReadsDoNotCrossCompanies(): void
    {
        self::bootKernel();
        [$company, $plan] = $this->planWithCatalog(['101'], '2026-07-01', '2026-07-03');
        $stranger = $this->company();

        self::assertNull($this->service(ShowSalesPlanAction::class)($stranger->id()->toRfc4122(), $plan->id()->toRfc4122(), SalesPlanGrouping::Period, null, 50));
        self::assertSame([], $this->service(ListSalesPlansAction::class)($stranger->id()->toRfc4122(), null, null, 50)->items);
        self::assertSame(
            SetSalesPlanSkusOutcome::NotFound,
            $this->setSkus($stranger, $plan, $plan->version(), [new SkuPeriodInput('101', 1, 100)])->outcome,
        );

        $own = $this->service(ListSalesPlansAction::class)($company->id()->toRfc4122(), null, null, 50);
        self::assertSame([$plan->id()->toRfc4122()], array_map(static fn ($row): string => $row->id, $own->items));
    }

    /**
     * @param list<string> $catalog
     *
     * @return array{Company, SalesPlan}
     */
    private function planWithCatalog(array $catalog, string $from, string $to): array
    {
        $company = $this->company();
        $account = $this->account($company);

        /** @var MarketplaceListingRepository $listings */
        $listings = self::getContainer()->get(MarketplaceListingRepository::class);
        $listings->replaceForAccount(
            $company->id()->toRfc4122(),
            $account->id(),
            array_map(
                static fn (string $sku) => MarketplaceListingBuilder::aMarketplaceListing()
                    ->withCompanyId($company->id())
                    ->withMarketplaceAccountId($account->id())
                    ->withMarketplaceSku($sku)
                    ->build(),
                $catalog,
            ),
        );

        $plan = $this->create($company, $account, $from, $to);
        self::assertNotNull($plan);

        return [$company, $plan];
    }

    private function create(Company $company, MarketplaceAccount $account, string $from = '2026-07-01', string $to = '2026-07-31'): ?SalesPlan
    {
        return $this->service(CreateSalesPlanAction::class)(
            $company->id()->toRfc4122(),
            $account->id()->toRfc4122(),
            'План',
            new \DateTimeImmutable($from),
            new \DateTimeImmutable($to),
            'RUB',
            self::Actor,
        );
    }

    /**
     * @param list<SkuPeriodInput> $items
     */
    private function setSkus(Company $company, SalesPlan $plan, int $version, array $items): \App\SalesPlanning\Application\SetSalesPlanSkusResult
    {
        return $this->service(SetSalesPlanSkusAction::class)(
            $company->id()->toRfc4122(),
            $plan->id()->toRfc4122(),
            $version,
            $items,
            self::Actor,
        );
    }

    private function changeDay(Company $company, SalesPlan $plan, int $version, string $sku, string $date, int $quantity, int $priceMinor): EditSalesPlanResult
    {
        return $this->service(ChangeSalesPlanDayAction::class)(
            $company->id()->toRfc4122(),
            $plan->id()->toRfc4122(),
            $version,
            $sku,
            new \DateTimeImmutable($date),
            $quantity,
            $priceMinor,
            self::Actor,
        );
    }

    /**
     * Строки в обход сценария — только чтобы дойти до лимита без
     * десятков тысяч ORM-вставок в тесте.
     */
    private function bulkLines(SalesPlan $plan, int $skus): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO sales_plan_line (id, company_id, sales_plan_id, marketplace_sku, plan_date, quantity, price_minor)
             SELECT gen_random_uuid(), :companyId, :planId, 'bulk-' || s, d::date, 1, 100
               FROM generate_series(1, :skus) AS s
              CROSS JOIN generate_series(:from::date, :to::date, interval '1 day') AS d",
            [
                'companyId' => $plan->companyId()->toRfc4122(),
                'planId' => $plan->id()->toRfc4122(),
                'skus' => $skus,
                'from' => $plan->dateFrom()->format('Y-m-d'),
                'to' => $plan->dateTo()->format('Y-m-d'),
            ],
        );
    }

    /**
     * @return list<array{string, string, int, int}>
     */
    private function lines(SalesPlan $plan): array
    {
        /** @var list<array{marketplace_sku: string, plan_date: string, quantity: int, price_minor: int}> $rows */
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT marketplace_sku, plan_date, quantity, price_minor FROM sales_plan_line
              WHERE company_id = :companyId AND sales_plan_id = :planId ORDER BY marketplace_sku, plan_date',
            ['companyId' => $plan->companyId()->toRfc4122(), 'planId' => $plan->id()->toRfc4122()],
        );

        return array_map(static fn (array $row): array => [$row['marketplace_sku'], $row['plan_date'], $row['quantity'], $row['price_minor']], $rows);
    }

    /**
     * @return list<string>
     */
    private function lineIds(SalesPlan $plan): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection()->fetchFirstColumn(
            'SELECT id FROM sales_plan_line WHERE company_id = :companyId AND sales_plan_id = :planId ORDER BY id',
            ['companyId' => $plan->companyId()->toRfc4122(), 'planId' => $plan->id()->toRfc4122()],
        );

        return $ids;
    }

    /**
     * @return list<array{previous_value: string|null, new_value: string|null}>
     */
    private function audit(SalesPlan $plan, string $action): array
    {
        /** @var list<array{previous_value: string|null, new_value: string|null}> $rows */
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT previous_value, new_value FROM audit_record
              WHERE company_id = :companyId AND subject_id = :planId AND action = :action ORDER BY occurred_at, id',
            ['companyId' => $plan->companyId()->toRfc4122(), 'planId' => $plan->id()->toRfc4122(), 'action' => $action],
        );

        return $rows;
    }

    private function auditCount(SalesPlan $plan, string $action): int
    {
        return \count($this->audit($plan, $action));
    }

    private function company(): Company
    {
        /** @var CompanyRepository $companies */
        $companies = self::getContainer()->get(CompanyRepository::class);

        return CompanyBuilder::aCompany()->persistWith($companies);
    }

    private function account(Company $company, MarketplaceAccountState $state = MarketplaceAccountState::Active): MarketplaceAccount
    {
        /** @var CompanyRepository $companies */
        $companies = self::getContainer()->get(CompanyRepository::class);
        /** @var MarketplaceAccountRepository $accounts */
        $accounts = self::getContainer()->get(MarketplaceAccountRepository::class);

        return MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($company)
            ->withExternalShopId('shop-'.Uuid::v7()->toRfc4122())
            ->withState($state)
            ->persistWith($companies, $accounts);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        \assert($service instanceof $class);

        return $service;
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }
}
