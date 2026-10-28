<?php

declare(strict_types=1);

namespace App\Tests\Integration\SalesPlanning;

use App\SalesPlanning\Application\ApproveSalesPlanAction;
use App\SalesPlanning\Application\ApproveSalesPlanOutcome;
use App\SalesPlanning\Application\SetSalesPlanMetricAction;
use App\SalesPlanning\Application\SetSalesPlanMetricOutcome;
use App\SalesPlanning\Domain\SalesPlanMetric;
use App\SalesPlanning\Domain\SalesPlanRepository;
use App\Tests\Support\Builder\SalesPlanBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SetSalesPlanMetricTest extends KernelTestCase
{
    public function testExplicitChoicePersistsVersionAndAuditAndFirstDraftCanChange(): void
    {
        self::bootKernel();
        $repository = $this->repository();
        $plan = SalesPlanBuilder::aSalesPlan()->persistWith($repository);
        self::assertNull($plan->metric());
        $companyId = $plan->companyId()->toRfc4122();
        $planId = $plan->id()->toRfc4122();
        $actor = Uuid::v7()->toRfc4122();
        $action = $this->action();
        $result = $action($companyId, $planId, 1, SalesPlanMetric::RetainedSales, $actor);
        self::assertSame(SetSalesPlanMetricOutcome::Saved, $result->outcome);
        self::assertSame(2, $result->version);
        $connection = $this->connection();
        $audit = $connection->fetchAssociative('SELECT actor_user_id, previous_value, new_value FROM audit_record WHERE company_id = ? AND subject_id = ? AND action = ?', [$companyId, $planId, 'sales_plan.metric_selected']);
        self::assertIsArray($audit);
        self::assertSame($actor, $audit['actor_user_id']);
        self::assertIsString($audit['previous_value']);
        self::assertIsString($audit['new_value']);
        self::assertSame(['metric' => null], json_decode($audit['previous_value'], true, flags: \JSON_THROW_ON_ERROR));
        self::assertSame(['metric' => 'retained_sales', 'baselinePlanId' => $plan->lineageId()->toRfc4122()], json_decode($audit['new_value'], true, flags: \JSON_THROW_ON_ERROR));
        $this->entityManager()->clear();
        $loaded = $repository->get($companyId, $plan->id());
        self::assertNotNull($loaded);
        self::assertSame(SalesPlanMetric::RetainedSales, $loaded->metric());
        $same = $action($companyId, $planId, 2, SalesPlanMetric::RetainedSales, $actor);
        self::assertSame(2, $same->version);
        self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM audit_record WHERE company_id = ? AND subject_id = ?', [$companyId, $planId]));
        self::assertSame(3, $action($companyId, $planId, 2, SalesPlanMetric::OrdersExcludingPreHandoverCancellations, $actor)->version);
    }

    public function testMissingMetricApprovalAndStaleOrForeignChoiceWriteNothing(): void
    {
        self::bootKernel();
        $plan = SalesPlanBuilder::aSalesPlan()->persistWith($this->repository());
        $companyId = $plan->companyId()->toRfc4122();
        $planId = $plan->id()->toRfc4122();
        $actor = Uuid::v7()->toRfc4122();
        $action = $this->action();
        self::assertSame(SetSalesPlanMetricOutcome::NotFound, $action(Uuid::v7()->toRfc4122(), $planId, 1, SalesPlanMetric::RetainedSales, $actor)->outcome);
        self::assertSame(SetSalesPlanMetricOutcome::VersionConflict, $action($companyId, $planId, 99, SalesPlanMetric::RetainedSales, $actor)->outcome);
        self::assertSame(ApproveSalesPlanOutcome::MissingMetric, $this->approval()($companyId, $planId, 1, $actor)->outcome);
        self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM audit_record WHERE company_id = ? AND subject_id = ?', [$companyId, $planId]));
        self::assertNull($plan->metric());
        self::assertNull($plan->approvedAt());
    }

    public function testConcurrentHeaderChangeRollsBackMetricAndAudit(): void
    {
        self::bootKernel();
        $plan = SalesPlanBuilder::aSalesPlan()->persistWith($this->repository());
        $companyId = $plan->companyId()->toRfc4122();
        $planId = $plan->id()->toRfc4122();
        $connection = $this->connection();
        $connection->executeStatement('UPDATE sales_plan SET version = version + 1 WHERE company_id = ? AND id = ?', [$companyId, $planId]);
        $result = $this->action()($companyId, $planId, 1, SalesPlanMetric::RetainedSales, Uuid::v7()->toRfc4122());
        self::assertSame(SetSalesPlanMetricOutcome::VersionConflict, $result->outcome);
        self::assertNull($connection->fetchOne('SELECT metric FROM sales_plan WHERE company_id = ? AND id = ?', [$companyId, $planId]));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM audit_record WHERE company_id = ? AND subject_id = ?', [$companyId, $planId]));
    }

    public function testPersistedInheritedTypedMetricCannotChange(): void
    {
        self::bootKernel();
        $source = SalesPlanBuilder::aSalesPlan()->withMetric(SalesPlanMetric::RetainedSales)->build();
        $source->approve(Uuid::v7(), new \DateTimeImmutable());
        $this->repository()->add($source, []);
        $plan = SalesPlanBuilder::revisionOf($source)->persistWith($this->repository());
        $companyId = $plan->companyId()->toRfc4122();
        $planId = $plan->id()->toRfc4122();
        $this->entityManager()->clear();
        $result = ($this->action())($companyId, $planId, 1, SalesPlanMetric::OrdersExcludingPreHandoverCancellations, Uuid::v7()->toRfc4122());
        self::assertSame(SetSalesPlanMetricOutcome::MetricImmutable, $result->outcome);
        self::assertSame('retained_sales', $this->connection()->fetchOne('SELECT metric FROM sales_plan WHERE company_id = ? AND id = ?', [$companyId, $planId]));
        self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM audit_record WHERE company_id = ? AND subject_id = ?', [$companyId, $planId]));
    }

    private function repository(): SalesPlanRepository
    {
        $service = self::getContainer()->get(SalesPlanRepository::class);
        \assert($service instanceof SalesPlanRepository);

        return $service;
    }

    private function action(): SetSalesPlanMetricAction
    {
        $service = self::getContainer()->get(SetSalesPlanMetricAction::class);
        \assert($service instanceof SetSalesPlanMetricAction);

        return $service;
    }

    private function connection(): Connection
    {
        $service = self::getContainer()->get(Connection::class);
        \assert($service instanceof Connection);

        return $service;
    }

    private function entityManager(): EntityManagerInterface
    {
        $service = self::getContainer()->get(EntityManagerInterface::class);
        \assert($service instanceof EntityManagerInterface);

        return $service;
    }

    private function approval(): ApproveSalesPlanAction
    {
        $service = self::getContainer()->get(ApproveSalesPlanAction::class);
        \assert($service instanceof ApproveSalesPlanAction);

        return $service;
    }
}
