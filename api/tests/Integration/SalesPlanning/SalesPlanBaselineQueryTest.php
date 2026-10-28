<?php

declare(strict_types=1);

namespace App\Tests\Integration\SalesPlanning;

use App\SalesPlanning\Application\ApproveSalesPlanAction;
use App\SalesPlanning\Application\ApproveSalesPlanOutcome;
use App\SalesPlanning\Application\CancelSalesPlanAction;
use App\SalesPlanning\Application\CloseSalesPlanOutcome;
use App\SalesPlanning\Application\CreateSalesPlanRevisionAction;
use App\SalesPlanning\Application\CreateSalesPlanRevisionOutcome;
use App\SalesPlanning\Application\DiscardSalesPlanAction;
use App\SalesPlanning\Domain\PlannedDay;
use App\SalesPlanning\Domain\SalesPlan;
use App\SalesPlanning\Domain\SalesPlanMetric;
use App\SalesPlanning\Domain\SalesPlanRepository;
use App\SalesPlanning\Infrastructure\Query\SalesPlanBaselineQuery;
use App\Tests\Support\Builder\SalesPlanBuilder;
use App\Tests\Support\Builder\SalesPlanLineBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SalesPlanBaselineQueryTest extends KernelTestCase
{
    public function testFirstApprovedGoalSurvivesDraftDiscardReplacementAndCancellation(): void
    {
        self::bootKernel();
        $first = $this->draft();
        self::assertFalse($this->baseline($first));
        $this->approve($first);
        $draft = $this->revision($first);
        $result = $this->service(DiscardSalesPlanAction::class)($first->companyId()->toRfc4122(), $draft->id()->toRfc4122(), $draft->version(), Uuid::v7()->toRfc4122());
        self::assertSame(CloseSalesPlanOutcome::Changed, $result->outcome);
        self::assertSame($first->id()->toRfc4122(), $this->baselineId($draft));
        $next = $this->revision($first);
        $next->recordLinesChanged(new \DateTimeImmutable());
        $this->repository()->saveDay($next, 'BASELINE', new PlannedDay($next->dateFrom(), 99, 777_777));
        $this->approve($next);
        $row = $this->baseline($next);
        self::assertIsArray($row);
        $baseline = SalesPlanBaselineQuery::mapRow($row);
        self::assertSame($first->id()->toRfc4122(), $baseline->id);
        self::assertSame(1, $baseline->versionNumber);
        self::assertSame('retained_sales', $baseline->metric);
        self::assertSame('RUB', $baseline->currency);
        self::assertSame('2026-07-01', $baseline->dateFrom);
        self::assertSame('2026-07-03', $baseline->dateTo);
        $cancel = $this->service(CancelSalesPlanAction::class)($next->companyId()->toRfc4122(), $next->id()->toRfc4122(), $next->version(), Uuid::v7()->toRfc4122());
        self::assertSame(CloseSalesPlanOutcome::Changed, $cancel->outcome);
        self::assertSame($first->id()->toRfc4122(), $this->baselineId($next));
        self::assertSame(7, $this->connection()->fetchOne('SELECT SUM(quantity) FROM sales_plan_line WHERE company_id = ? AND sales_plan_id = ?', [$first->companyId()->toRfc4122(), $first->id()->toRfc4122()]));
    }

    public function testDraftOnlyAndTenantOrOtherLineageDoNotInventOrReplaceBaseline(): void
    {
        self::bootKernel();
        $first = $this->draft();
        $discard = $this->service(DiscardSalesPlanAction::class)($first->companyId()->toRfc4122(), $first->id()->toRfc4122(), $first->version(), Uuid::v7()->toRfc4122());
        self::assertSame(CloseSalesPlanOutcome::Changed, $discard->outcome);
        self::assertFalse($this->baseline($first));
        $other = $this->draft($first->companyId(), $first->marketplaceAccountId());
        $this->approve($other);
        self::assertFalse($this->baseline($first));
        self::assertSame($other->id()->toRfc4122(), $this->baselineId($other));
        self::assertFalse((new SalesPlanBaselineQuery($this->connection()))->build(Uuid::v7()->toRfc4122(), $other->id()->toRfc4122())->executeQuery()->fetchAssociative());
    }

    public function testLegacyApprovalMetadataAndMetricMayBeNullWithoutLosingNumericGoal(): void
    {
        self::bootKernel();
        $first = $this->draft();
        $this->approve($first);
        // Deliberate legacy fixture for columns absent before this feature.
        $this->connection()->executeStatement('UPDATE sales_plan SET metric = NULL, approved_at = NULL, approved_by_user_id = NULL WHERE company_id = ? AND id = ?', [$first->companyId()->toRfc4122(), $first->id()->toRfc4122()]);
        $row = $this->baseline($first);
        self::assertIsArray($row);
        $baseline = SalesPlanBaselineQuery::mapRow($row);
        self::assertSame($first->id()->toRfc4122(), $baseline->id);
        self::assertNull($baseline->metric);
    }

    private function draft(?Uuid $company = null, ?Uuid $account = null): SalesPlan
    {
        $builder = SalesPlanBuilder::aSalesPlan()->withMetric(SalesPlanMetric::RetainedSales)
            ->withPeriod(new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-07-03'));
        if (null !== $company && null !== $account) {
            $builder = $builder->withCompanyId($company)->withMarketplaceAccountId($account);
        }
        $plan = $builder->build();
        $this->repository()->add($plan, SalesPlanLineBuilder::linesOf($plan)->withMarketplaceSku('BASELINE')->withTotalQuantity(7)->build());

        return $plan;
    }

    private function approve(SalesPlan $plan): void
    {
        self::assertSame(ApproveSalesPlanOutcome::Approved, $this->service(ApproveSalesPlanAction::class)($plan->companyId()->toRfc4122(), $plan->id()->toRfc4122(), $plan->version(), Uuid::v7()->toRfc4122())->outcome);
    }

    private function revision(SalesPlan $source): SalesPlan
    {
        $result = $this->service(CreateSalesPlanRevisionAction::class)($source->companyId()->toRfc4122(), $source->id()->toRfc4122(), $source->version(), Uuid::v7()->toRfc4122());
        self::assertSame(CreateSalesPlanRevisionOutcome::Created, $result->outcome);
        self::assertNotNull($result->draft);

        return $result->draft;
    }

    /** @return array<string, mixed>|false */
    private function baseline(SalesPlan $plan): array|false
    {
        return (new SalesPlanBaselineQuery($this->connection()))->build($plan->companyId()->toRfc4122(), $plan->id()->toRfc4122())->executeQuery()->fetchAssociative();
    }

    private function baselineId(SalesPlan $plan): string
    {
        $row = $this->baseline($plan);
        self::assertIsArray($row);

        return SalesPlanBaselineQuery::mapRow($row)->id;
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

    private function repository(): SalesPlanRepository
    {
        return $this->service(SalesPlanRepository::class);
    }

    private function connection(): Connection
    {
        return $this->service(Connection::class);
    }
}
