<?php

declare(strict_types=1);

namespace App\Tests\Integration\SalesPlanning;

use App\SalesPlanning\Application\ApproveSalesPlanAction;
use App\SalesPlanning\Application\ApproveSalesPlanOutcome;
use App\SalesPlanning\Application\CreateSalesPlanRevisionAction;
use App\SalesPlanning\Application\CreateSalesPlanRevisionOutcome;
use App\SalesPlanning\Application\EditSalesPlanOutcome;
use App\SalesPlanning\Application\RemoveSalesPlanSkuAction;
use App\SalesPlanning\Application\SetSalesPlanMetricAction;
use App\SalesPlanning\Application\SetSalesPlanMetricOutcome;
use App\SalesPlanning\Domain\PlannedDay;
use App\SalesPlanning\Domain\SalesPlan;
use App\SalesPlanning\Domain\SalesPlanMetric;
use App\SalesPlanning\Domain\SalesPlanRepository;
use App\SalesPlanning\Domain\SalesPlanStatus;
use App\SalesPlanning\Domain\SkuPeriodPlan;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\SalesPlanBuilder;
use App\Tests\Support\Builder\SalesPlanLineBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SalesPlanRevisionLifecycleTest extends KernelTestCase
{
    public function testLargeRevisionHonoursStatementLimitAndCopiesAcrossBatchBoundary(): void
    {
        self::bootKernel();
        $source = SalesPlanBuilder::aSalesPlan()->withMetric(SalesPlanMetric::RetainedSales)
            ->withPeriod(new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-12-31'))->build();
        $lines = [];
        for ($sku = 1; $sku <= 55; ++$sku) {
            $lines = [...$lines, ...SalesPlanLineBuilder::linesOf($source)->withMarketplaceSku(\sprintf('sku-%03d', $sku))->withPrice(Money::ofMinor(10000 + $sku, 'RUB'))->build()];
        }
        $this->repository()->add($source, $lines);
        $source->recordLinesChanged(new \DateTimeImmutable());
        $this->repository()->saveDay($source, 'sku-055', new PlannedDay(new \DateTimeImmutable('2026-09-02'), 0, 123456));
        $source->recordLinesChanged(new \DateTimeImmutable());
        $this->repository()->saveDay($source, 'sku-055', new PlannedDay(new \DateTimeImmutable('2026-09-03'), 9, 987654));
        self::assertSame(ApproveSalesPlanOutcome::Approved, $this->approve($source));
        $before = $this->lines($source);
        self::assertCount(10120, $before);
        self::assertSame(['sku-055', '2026-09-02', 0, 123456], $this->values([$before[9999]])[0]);
        self::assertSame(['sku-055', '2026-09-03', 9, 987654], $this->values([$before[10000]])[0]);
        // Resource contract: ограничиваем реальный statement, не текст SQL.
        // DDL и временная функция откатываются вместе с транзакцией DAMA.
        $this->connection()->executeStatement(<<<'SQL'
            CREATE FUNCTION pg_temp.enforce_plan_copy_batch_limit() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT COUNT(*) FROM inserted_plan_days) > 10000 THEN
                    RAISE EXCEPTION 'sales_plan_line statement exceeds 10000 rows';
                END IF;
                RETURN NULL;
            END;
            $$
            SQL);
        $this->connection()->executeStatement('CREATE TRIGGER enforce_plan_copy_batch_limit AFTER INSERT ON sales_plan_line REFERENCING NEW TABLE AS inserted_plan_days FOR EACH STATEMENT EXECUTE FUNCTION pg_temp.enforce_plan_copy_batch_limit()');
        $draft = $this->revision($source);
        $copied = $this->lines($draft);
        self::assertCount(10120, $copied);
        self::assertSame($this->values($before), $this->values($copied));
        self::assertSame($before, $this->lines($source));
        self::assertSame([], array_intersect(array_column($before, 'id'), array_column($copied, 'id')));
        foreach ($copied as $row) {
            self::assertSame('7', substr($row['id'], 14, 1));
        }
    }

    public function testExactCopyNewIdsReuseAndSourceStaysApprovedDuringDraftEdits(): void
    {
        self::bootKernel();
        $source = $this->source();
        $before = $this->lines($source);
        $version = $source->version();
        $draft = $this->revision($source);
        self::assertSame(2, $draft->versionNumber());
        self::assertSame(SalesPlanStatus::Approved, $source->status());
        self::assertSame($version + 1, $source->version());
        self::assertSame($this->values($before), $this->values($this->lines($draft)));
        self::assertSame([], array_intersect(array_column($before, 'id'), array_column($this->lines($draft), 'id')));
        foreach ($this->lines($draft) as $row) {
            self::assertSame('7', substr($row['id'], 14, 1));
        }
        self::assertSame(SalesPlanMetric::RetainedSales, $draft->metric());
        self::assertSame(SetSalesPlanMetricOutcome::MetricImmutable, $this->service(SetSalesPlanMetricAction::class)($draft->companyId()->toRfc4122(), $draft->id()->toRfc4122(), $draft->version(), SalesPlanMetric::OrdersExcludingPreHandoverCancellations, Uuid::v7()->toRfc4122())->outcome);
        self::assertNull($draft->approvedAt());
        self::assertNull($draft->approvedByUserId());
        self::assertSame(CreateSalesPlanRevisionOutcome::VersionConflict, $this->service(CreateSalesPlanRevisionAction::class)($source->companyId()->toRfc4122(), $source->id()->toRfc4122(), $version, Uuid::v7()->toRfc4122())->outcome);
        self::assertSame($draft->id(), $this->revision($source)->id());
        $removed = $this->service(RemoveSalesPlanSkuAction::class)($draft->companyId()->toRfc4122(), $draft->id()->toRfc4122(), $draft->version(), '102', Uuid::v7()->toRfc4122());
        self::assertSame(EditSalesPlanOutcome::Saved, $removed->outcome);
        self::assertSame($before, $this->lines($source));
        self::assertSame('approved', $this->header($source)['status']);
        self::assertSame(6, $this->claimCount($source));
        self::assertSame(0, $this->claimCount($draft));
    }

    public function testReplacementReleasesRemovedSkuClaimsAndPreservesOriginalHistory(): void
    {
        self::bootKernel();
        $source = $this->source();
        $original = $this->lines($source);
        $approvedAt = $source->approvedAt();
        $draft = $this->revision($source);
        $this->service(RemoveSalesPlanSkuAction::class)($draft->companyId()->toRfc4122(), $draft->id()->toRfc4122(), $draft->version(), '102', Uuid::v7()->toRfc4122());
        self::assertSame(ApproveSalesPlanOutcome::Approved, $this->approve($draft));
        self::assertSame(SalesPlanStatus::Superseded, $source->status());
        self::assertSame($approvedAt, $source->approvedAt());
        self::assertSame($original, $this->lines($source));
        self::assertSame(0, $this->claimCount($source));
        self::assertSame(3, $this->claimCount($draft));
        $third = $this->source($source, ['102']);
        self::assertSame(SalesPlanStatus::Approved, $third->status());
        self::assertSame(EditSalesPlanOutcome::NotEditable, $this->service(RemoveSalesPlanSkuAction::class)($source->companyId()->toRfc4122(), $source->id()->toRfc4122(), $source->version(), '101', Uuid::v7()->toRfc4122())->outcome);
        self::assertSame(1, $this->auditCount($source, 'sales_plan.superseded'));
        self::assertSame(1, $this->auditCount($draft, 'sales_plan.approved'));
    }

    public function testThirdPlanConflictRollsBackBothHeadersAllClaimsAndAudits(): void
    {
        self::bootKernel();
        $source = $this->source();
        $this->source($source, ['999']);
        $draft = $this->revision($source);
        $draft->recordLinesChanged(new \DateTimeImmutable());
        $this->repository()->saveSkuPlans($draft, [SkuPeriodPlan::stored('999', [new PlannedDay(new \DateTimeImmutable('2026-07-01'), 3, 100)])]);
        $sourceBefore = $this->header($source);
        $draftBefore = $this->header($draft);
        self::assertSame(ApproveSalesPlanOutcome::DayConflict, $this->approve($draft));
        self::assertSame($sourceBefore, $this->header($source));
        self::assertSame($draftBefore, $this->header($draft));
        self::assertSame(6, $this->claimCount($source));
        self::assertSame(0, $this->claimCount($draft));
        self::assertSame(0, $this->auditCount($source, 'sales_plan.superseded'));
        self::assertSame(0, $this->auditCount($draft, 'sales_plan.approved'));
    }

    public function testConcurrentTargetEditRollsBackSourceSupersessionAndClaims(): void
    {
        self::bootKernel();
        $source = $this->source();
        $draft = $this->revision($source);
        $sourceBefore = $this->header($source);
        $this->connection()->executeStatement('UPDATE sales_plan SET version = version + 1 WHERE company_id = ? AND id = ?', [$draft->companyId()->toRfc4122(), $draft->id()->toRfc4122()]);
        self::assertSame(ApproveSalesPlanOutcome::VersionConflict, $this->approve($draft));
        self::assertSame($sourceBefore, $this->header($source));
        self::assertSame(6, $this->claimCount($source));
        self::assertSame(0, $this->claimCount($draft));
        self::assertSame(0, $this->auditCount($source, 'sales_plan.superseded'));
        self::assertSame(0, $this->auditCount($draft, 'sales_plan.approved'));
    }

    public function testEmptyReplacementPreservesActiveSource(): void
    {
        self::bootKernel();
        $source = $this->source();
        $draft = $this->revision($source);
        foreach (['101', '102'] as $sku) {
            $this->service(RemoveSalesPlanSkuAction::class)($draft->companyId()->toRfc4122(), $draft->id()->toRfc4122(), $draft->version(), $sku, Uuid::v7()->toRfc4122());
        }
        $sourceBefore = $this->header($source);
        self::assertSame(ApproveSalesPlanOutcome::EmptyPlan, $this->approve($draft));
        self::assertSame($sourceBefore, $this->header($source));
        self::assertSame(6, $this->claimCount($source));
        self::assertSame(0, $this->auditCount($source, 'sales_plan.superseded'));
    }

    public function testClosedLineageRejectsReplacementWithoutAnyWrites(): void
    {
        self::bootKernel();
        $source = $this->source();
        $draft = $this->revision($source);
        // Cancellation command приходит в A09; precise fixture закрывает approved.
        $this->connection()->executeStatement("UPDATE sales_plan SET status = 'cancelled' WHERE company_id = ? AND id = ?", [$source->companyId()->toRfc4122(), $source->id()->toRfc4122()]);
        $this->service(EntityManagerInterface::class)->clear();
        $draft = $this->repository()->get($draft->companyId()->toRfc4122(), $draft->id());
        self::assertNotNull($draft);
        $before = $this->header($draft);
        self::assertSame(ApproveSalesPlanOutcome::ClosedLineage, $this->approve($draft));
        self::assertSame($before, $this->header($draft));
        self::assertSame(0, $this->auditCount($draft, 'sales_plan.approved'));
    }

    public function testLegacyNullGoalCanBeExplicitlyInterpretedOnActualRevisionAndReplaced(): void
    {
        self::bootKernel();
        $source = $this->source();
        $original = $this->lines($source);
        // Точная historical fixture: штатный Builder/approval, затем только legacy NULL.
        $this->connection()->executeStatement('UPDATE sales_plan SET metric = NULL WHERE company_id = ? AND id = ?', [$source->companyId()->toRfc4122(), $source->id()->toRfc4122()]);
        $em = $this->service(EntityManagerInterface::class);
        $em->clear();
        $source = $this->repository()->get($source->companyId()->toRfc4122(), $source->id());
        self::assertNotNull($source);
        $draft = $this->revision($source);
        self::assertNull($draft->metric());
        self::assertSame(ApproveSalesPlanOutcome::MissingMetric, $this->approve($draft));
        $selected = $this->service(SetSalesPlanMetricAction::class)($draft->companyId()->toRfc4122(), $draft->id()->toRfc4122(), $draft->version(), SalesPlanMetric::RetainedSales, Uuid::v7()->toRfc4122());
        self::assertSame(SetSalesPlanMetricOutcome::Saved, $selected->outcome);
        self::assertSame(ApproveSalesPlanOutcome::Approved, $this->approve($draft));
        self::assertNull($source->metric());
        self::assertSame($original, $this->lines($source));
        $value = $this->connection()->fetchOne("SELECT new_value FROM audit_record WHERE company_id = ? AND subject_id = ? AND action = 'sales_plan.metric_selected'", [$draft->companyId()->toRfc4122(), $draft->id()->toRfc4122()]);
        self::assertIsString($value);
        $audit = json_decode($value, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($audit);
        self::assertSame($source->id()->toRfc4122(), $audit['baselinePlanId']);
    }

    public function testSourceOptimisticRaceCreatesNoDraftOrAudit(): void
    {
        self::bootKernel();
        $source = $this->source();
        $this->connection()->executeStatement('UPDATE sales_plan SET version = version + 1 WHERE company_id = ? AND id = ?', [$source->companyId()->toRfc4122(), $source->id()->toRfc4122()]);
        $result = $this->service(CreateSalesPlanRevisionAction::class)($source->companyId()->toRfc4122(), $source->id()->toRfc4122(), $source->version(), Uuid::v7()->toRfc4122());
        self::assertSame(CreateSalesPlanRevisionOutcome::VersionConflict, $result->outcome);
        self::assertSame(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM sales_plan WHERE company_id = ? AND lineage_id = ?', [$source->companyId()->toRfc4122(), $source->lineageId()->toRfc4122()]));
    }

    public function testTenantAndStatusGuardsAndHistoricalVersionNumbers(): void
    {
        self::bootKernel();
        $source = $this->source();
        $create = $this->service(CreateSalesPlanRevisionAction::class);
        self::assertSame(CreateSalesPlanRevisionOutcome::NotFound, $create(Uuid::v7()->toRfc4122(), $source->id()->toRfc4122(), $source->version(), Uuid::v7()->toRfc4122())->outcome);
        $discarded = $this->revision($source);
        self::assertSame(CreateSalesPlanRevisionOutcome::NotApproved, $create($discarded->companyId()->toRfc4122(), $discarded->id()->toRfc4122(), $discarded->version(), Uuid::v7()->toRfc4122())->outcome);
        // Lifecycle discard — будущий A09. Здесь изменяем только fixture status, не номер.
        $this->connection()->executeStatement("UPDATE sales_plan SET status = 'discarded' WHERE company_id = ? AND id = ?", [$discarded->companyId()->toRfc4122(), $discarded->id()->toRfc4122()]);
        $this->service(EntityManagerInterface::class)->clear();
        $source = $this->repository()->get($source->companyId()->toRfc4122(), $source->id());
        self::assertNotNull($source);
        $next = $this->revision($source);
        self::assertSame(3, $next->versionNumber());
        self::assertSame(ApproveSalesPlanOutcome::Approved, $this->approve($next));
        self::assertSame(CreateSalesPlanRevisionOutcome::NotApproved, $create($source->companyId()->toRfc4122(), $source->id()->toRfc4122(), $source->version(), Uuid::v7()->toRfc4122())->outcome);
    }

    /** @param list<string> $skus */
    private function source(?SalesPlan $accountOf = null, array $skus = ['101', '102']): SalesPlan
    {
        $builder = SalesPlanBuilder::aSalesPlan()->withMetric(SalesPlanMetric::RetainedSales)->withPeriod(new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-07-03'));
        if (null !== $accountOf) {
            $builder = $builder->withCompanyId($accountOf->companyId())->withMarketplaceAccountId($accountOf->marketplaceAccountId());
        }
        $plan = $builder->build();
        $lines = [];
        foreach ($skus as $sku) {
            $lines = [...$lines, ...SalesPlanLineBuilder::linesOf($plan)->withMarketplaceSku($sku)->withTotalQuantity(7)->build()];
        }
        $this->repository()->add($plan, $lines);
        $plan->recordLinesChanged(new \DateTimeImmutable());
        $this->repository()->saveDay($plan, $skus[0], new PlannedDay(new \DateTimeImmutable('2026-07-02'), 0, 121_121));
        self::assertSame(ApproveSalesPlanOutcome::Approved, $this->approve($plan));

        return $plan;
    }

    private function revision(SalesPlan $source): SalesPlan
    {
        $result = $this->service(CreateSalesPlanRevisionAction::class)($source->companyId()->toRfc4122(), $source->id()->toRfc4122(), $source->version(), Uuid::v7()->toRfc4122());
        self::assertSame(CreateSalesPlanRevisionOutcome::Created, $result->outcome);
        self::assertNotNull($result->draft);

        return $result->draft;
    }

    private function approve(SalesPlan $plan): ApproveSalesPlanOutcome
    {
        return $this->service(ApproveSalesPlanAction::class)($plan->companyId()->toRfc4122(), $plan->id()->toRfc4122(), $plan->version(), Uuid::v7()->toRfc4122())->outcome;
    }

    /** @return list<array{id: string, marketplace_sku: string, plan_date: string, quantity: int, price_minor: int}> */
    private function lines(SalesPlan $plan): array
    {
        /** @var list<array{id: string, marketplace_sku: string, plan_date: string, quantity: int, price_minor: int}> $rows */
        $rows = $this->connection()->fetchAllAssociative('SELECT id, marketplace_sku, plan_date, quantity, price_minor FROM sales_plan_line WHERE company_id = ? AND sales_plan_id = ? ORDER BY marketplace_sku, plan_date', [$plan->companyId()->toRfc4122(), $plan->id()->toRfc4122()]);

        return $rows;
    }

    /** @param list<array{id: string, marketplace_sku: string, plan_date: string, quantity: int, price_minor: int}> $rows
     * @return list<array{string, string, int, int}>
     */
    private function values(array $rows): array
    {
        return array_map(static fn (array $r): array => [$r['marketplace_sku'], $r['plan_date'], $r['quantity'], $r['price_minor']], $rows);
    }

    /** @return array<string, mixed> */
    private function header(SalesPlan $plan): array
    {
        $row = $this->connection()->fetchAssociative('SELECT status, version, approved_at, approved_by_user_id FROM sales_plan WHERE company_id = ? AND id = ?', [$plan->companyId()->toRfc4122(), $plan->id()->toRfc4122()]);
        self::assertIsArray($row);

        return $row;
    }

    private function claimCount(SalesPlan $plan): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM sales_plan_day_claim WHERE company_id = ? AND sales_plan_id = ?', [$plan->companyId()->toRfc4122(), $plan->id()->toRfc4122()]);
        \assert(\is_int($count));

        return $count;
    }

    private function auditCount(SalesPlan $plan, string $action): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM audit_record WHERE company_id = ? AND subject_id = ? AND action = ?', [$plan->companyId()->toRfc4122(), $plan->id()->toRfc4122(), $action]);
        \assert(\is_int($count));

        return $count;
    }

    private function repository(): SalesPlanRepository
    {
        return $this->service(SalesPlanRepository::class);
    }

    private function connection(): Connection
    {
        return $this->service(Connection::class);
    }

    /** @template T of object
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
}
