<?php

declare(strict_types=1);

namespace App\Tests\Integration\SalesPlanning;

use App\Identity\Application\Facade\IdentityFacade;
use App\SalesPlanning\Application\ApproveSalesPlanAction;
use App\SalesPlanning\Application\ApproveSalesPlanOutcome;
use App\SalesPlanning\Domain\SalesPlan;
use App\SalesPlanning\Domain\SalesPlanRepository;
use App\Tests\Support\Builder\SalesPlanBuilder;
use App\Tests\Support\Builder\SalesPlanLineBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ApproveSalesPlanTest extends KernelTestCase
{
    public function testApprovalKeepsNumbersAndWritesClaimsHeaderAndAudit(): void
    {
        self::bootKernel();
        $plan = $this->plan();
        $before = $this->rows($plan, 'sales_plan_line');
        $version = $plan->version();
        $result = $this->approve($plan, $version);
        self::assertSame(ApproveSalesPlanOutcome::Approved, $result->outcome);
        self::assertSame($version + 1, $result->version);
        self::assertSame($before, $this->rows($plan, 'sales_plan_line'));
        self::assertCount(3, $this->rows($plan, 'sales_plan_day_claim'));
        self::assertSame(1, $this->auditCount($plan));
        self::assertNotNull($plan->approvedByUserId());
        self::assertNotNull($plan->approvedAt());
        self::assertSame(ApproveSalesPlanOutcome::NotDraft, $this->approve($plan, $plan->version())->outcome);
    }

    public function testEmptyStaleAndForeignPlanAreRejectedWithoutWrites(): void
    {
        self::bootKernel();
        $plan = $this->plan(false);
        self::assertSame(ApproveSalesPlanOutcome::VersionConflict, $this->approve($plan, 999)->outcome);
        self::assertSame(ApproveSalesPlanOutcome::NotFound, $this->action()(Uuid::v7()->toRfc4122(), $plan->id()->toRfc4122(), $plan->version(), Uuid::v7()->toRfc4122())->outcome);
        self::assertSame(ApproveSalesPlanOutcome::EmptyPlan, $this->approve($plan, $plan->version())->outcome);
        self::assertSame(0, $this->auditCount($plan));
        self::assertSame([], $this->rows($plan, 'sales_plan_day_claim'));
    }

    public function testIntersectionRollsBackEvenUncontestedClaimsAndAudit(): void
    {
        self::bootKernel();
        $winner = $this->plan();
        $this->approve($winner, $winner->version());
        $loser = $this->plan(true, $winner);
        $version = $loser->version();
        $result = $this->approve($loser, $version);
        self::assertSame(ApproveSalesPlanOutcome::DayConflict, $result->outcome);
        self::assertCount(3, $result->conflicts);
        self::assertSame([], $this->rows($loser, 'sales_plan_day_claim'));
        self::assertSame(0, $this->auditCount($loser));
        $stored = $this->connection()->fetchAssociative('SELECT status, version, approved_at FROM sales_plan WHERE company_id = ? AND id = ?', [$loser->companyId()->toRfc4122(), $loser->id()->toRfc4122()]);
        self::assertSame(['status' => 'draft', 'version' => $version, 'approved_at' => null], $stored);
    }

    public function testSameSkuDaysInAnotherCompanyAreIndependent(): void
    {
        self::bootKernel();
        $first = $this->plan();
        $second = $this->plan();
        self::assertSame(ApproveSalesPlanOutcome::Approved, $this->approve($first, $first->version())->outcome);
        self::assertSame(ApproveSalesPlanOutcome::Approved, $this->approve($second, $second->version())->outcome);
    }

    public function testConflictListIsBoundedAndContainsOnlyRequestedDays(): void
    {
        self::bootKernel();
        $builder = SalesPlanBuilder::aSalesPlan()->withMetric(\App\SalesPlanning\Domain\SalesPlanMetric::RetainedSales)->withPeriod(new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-10-09'));
        $winner = $builder->build();
        $loser = $builder->withCompanyId($winner->companyId())->withMarketplaceAccountId($winner->marketplaceAccountId())->build();
        $repository = self::getContainer()->get(SalesPlanRepository::class);
        \assert($repository instanceof SalesPlanRepository);
        foreach ([$winner, $loser] as $plan) {
            $repository->add($plan, [
                ...SalesPlanLineBuilder::linesOf($plan)->withMarketplaceSku('101')->build(),
                ...SalesPlanLineBuilder::linesOf($plan)->withMarketplaceSku('102')->build(),
            ]);
        }
        self::assertSame(ApproveSalesPlanOutcome::Approved, $this->approve($winner, $winner->version())->outcome);
        $result = $this->approve($loser, $loser->version());
        self::assertSame(ApproveSalesPlanOutcome::DayConflict, $result->outcome);
        self::assertCount(200, $result->conflicts);
        self::assertSame('101', $result->conflicts[0]->marketplaceSku);
        self::assertSame('2026-07-01', $result->conflicts[0]->date);
        self::assertSame([], $this->rows($loser, 'sales_plan_day_claim'));
    }

    public function testConcurrentHeaderChangeRollsBackApprovalAndAudit(): void
    {
        self::bootKernel();
        $plan = $this->plan();
        $version = $plan->version();
        // Имитируем UPDATE другого запроса после загрузки шапки: память
        // ещё содержит прежнюю версию, защитить запись должен сам UPDATE ORM.
        $this->connection()->executeStatement('UPDATE sales_plan SET version = version + 1 WHERE company_id = ? AND id = ?', [$plan->companyId()->toRfc4122(), $plan->id()->toRfc4122()]);
        self::assertSame(ApproveSalesPlanOutcome::VersionConflict, $this->approve($plan, $version)->outcome);
        self::assertSame([], $this->rows($plan, 'sales_plan_day_claim'));
        self::assertSame(0, $this->auditCount($plan));
        self::assertSame('draft', $this->connection()->fetchOne('SELECT status FROM sales_plan WHERE company_id = ? AND id = ?', [$plan->companyId()->toRfc4122(), $plan->id()->toRfc4122()]));
    }

    public function testRepositoryRejectsMismatchedCompanyBeforeFlushingPendingChanges(): void
    {
        self::bootKernel();
        $plan = $this->plan();
        $version = $plan->version();
        $actor = Uuid::v7();
        $plan->approve($actor, new \DateTimeImmutable());
        $identity = self::getContainer()->get(IdentityFacade::class);
        \assert($identity instanceof IdentityFacade);
        $identity->recordAuditEntry($plan->companyId()->toRfc4122(), $actor->toRfc4122(), 'sales_plan.approved', $plan->id()->toRfc4122(), null, 'approved');
        $repository = self::getContainer()->get(SalesPlanRepository::class);
        \assert($repository instanceof SalesPlanRepository);

        try {
            $repository->approve(Uuid::v7()->toRfc4122(), $plan);
            self::fail('Чужой companyId должен быть отклонён до flush.');
        } catch (\InvalidArgumentException) {
            $stored = $this->connection()->fetchAssociative('SELECT status, version, approved_at FROM sales_plan WHERE company_id = ? AND id = ?', [$plan->companyId()->toRfc4122(), $plan->id()->toRfc4122()]);
            self::assertSame(['status' => 'draft', 'version' => $version, 'approved_at' => null], $stored);
            self::assertSame([], $this->rows($plan, 'sales_plan_day_claim'));
            self::assertSame(0, $this->auditCount($plan));
            $entityManager = self::getContainer()->get(EntityManagerInterface::class);
            \assert($entityManager instanceof EntityManagerInterface);
            self::assertTrue($entityManager->isOpen(), 'Отказ до транзакции не закрывает EntityManager.');
        }
    }

    private function plan(bool $withLines = true, ?SalesPlan $sameAccount = null): SalesPlan
    {
        $builder = SalesPlanBuilder::aSalesPlan()->withMetric(\App\SalesPlanning\Domain\SalesPlanMetric::RetainedSales)->withPeriod(new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-07-03'));
        if (null !== $sameAccount) {
            $builder = $builder->withCompanyId($sameAccount->companyId())->withMarketplaceAccountId($sameAccount->marketplaceAccountId());
        }
        $plan = $builder->build();
        $lines = $withLines ? SalesPlanLineBuilder::linesOf($plan)->withMarketplaceSku('101')->withTotalQuantity(0)->build() : [];
        if (null !== $sameAccount) {
            $lines = [...$lines, ...SalesPlanLineBuilder::linesOf($plan)->withMarketplaceSku('102')->build()];
        }
        $repository = self::getContainer()->get(SalesPlanRepository::class);
        \assert($repository instanceof SalesPlanRepository);
        $repository->add($plan, $lines);

        return $plan;
    }

    private function approve(SalesPlan $plan, int $version): \App\SalesPlanning\Application\ApproveSalesPlanResult
    {
        return $this->action()($plan->companyId()->toRfc4122(), $plan->id()->toRfc4122(), $version, Uuid::v7()->toRfc4122());
    }

    private function action(): ApproveSalesPlanAction
    {
        $action = self::getContainer()->get(ApproveSalesPlanAction::class);
        \assert($action instanceof ApproveSalesPlanAction);

        return $action;
    }

    /** @return list<array<string, mixed>> */
    private function rows(SalesPlan $plan, string $table): array
    {
        return $this->connection()->fetchAllAssociative('SELECT * FROM '.$table.' WHERE company_id = ? AND sales_plan_id = ? ORDER BY marketplace_sku, plan_date', [$plan->companyId()->toRfc4122(), $plan->id()->toRfc4122()]);
    }

    private function auditCount(SalesPlan $plan): int
    {
        $count = $this->connection()->fetchOne("SELECT COUNT(*) FROM audit_record WHERE company_id = ? AND subject_id = ? AND action = 'sales_plan.approved'", [$plan->companyId()->toRfc4122(), $plan->id()->toRfc4122()]);
        \assert(\is_int($count));

        return $count;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        return $connection;
    }
}
