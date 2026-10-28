<?php

declare(strict_types=1);

namespace App\Tests\Integration\SalesPlanning;

use App\Identity\Application\Facade\IdentityFacade;
use App\SalesPlanning\Application\ApproveSalesPlanAction;
use App\SalesPlanning\Application\ApproveSalesPlanOutcome;
use App\SalesPlanning\Application\CreateSalesPlanRevisionAction;
use App\SalesPlanning\Application\CreateSalesPlanRevisionOutcome;
use App\SalesPlanning\Application\EditSalesPlanOutcome;
use App\SalesPlanning\Application\ReadSalesPlanCalendarAction;
use App\SalesPlanning\Application\RemoveSalesPlanSkuAction;
use App\SalesPlanning\Application\SalesPlanCalendarInput;
use App\SalesPlanning\Application\SetSalesPlanCalendarAction;
use App\SalesPlanning\Application\SetSalesPlanCalendarOutcome;
use App\SalesPlanning\Domain\PlannedCalendarEvent;
use App\SalesPlanning\Domain\SalesPlan;
use App\SalesPlanning\Domain\SalesPlanCalendarKind;
use App\SalesPlanning\Domain\SalesPlanMetric;
use App\SalesPlanning\Domain\SalesPlanRepository;
use App\SalesPlanning\Infrastructure\Persistence\DoctrineSalesPlanCalendarRepository;
use App\SalesPlanning\Infrastructure\Query\SalesPlanCalendarQuery;
use App\Tests\Support\Builder\SalesPlanBuilder;
use App\Tests\Support\Builder\SalesPlanLineBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SetSalesPlanCalendarTest extends KernelTestCase
{
    public function testSnapshotPreservesIdsAndAuditsExactChangesWithoutChangingDailyGoal(): void
    {
        self::bootKernel();
        $plan = $this->plan();
        $actor = Uuid::v7()->toRfc4122();
        $action = $this->action();
        $before = $plan->version();
        $saved = $action($plan->companyId()->toRfc4122(), $plan->id()->toRfc4122(), $before, [
            new SalesPlanCalendarInput(null, new PlannedCalendarEvent(SalesPlanCalendarKind::Sales, null, $plan->dateFrom(), $plan->dateTo())),
            new SalesPlanCalendarInput(null, new PlannedCalendarEvent(SalesPlanCalendarKind::Replenishment, 'KNOWN', $plan->dateFrom(), $plan->dateFrom())),
        ], $actor);
        self::assertSame(SetSalesPlanCalendarOutcome::Saved, $saved->outcome);
        self::assertSame($before + 1, $saved->version);
        $entries = $this->calendar()->entriesForUpdate($plan->companyId()->toRfc4122(), $plan->id());
        self::assertCount(2, $entries);
        $sales = array_values(array_filter($entries, static fn ($entry): bool => SalesPlanCalendarKind::Sales === $entry->event()->kind))[0];
        $audit = $this->connection()->fetchAssociative('SELECT actor_user_id, new_value FROM audit_record WHERE company_id = ? AND subject_id = ? AND action = ?', [$plan->companyId()->toRfc4122(), $sales->id()->toRfc4122(), 'sales_plan.calendar_created']);
        self::assertIsArray($audit);
        self::assertSame($actor, $audit['actor_user_id']);
        self::assertIsString($audit['new_value']);
        $value = json_decode($audit['new_value'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($value);
        self::assertSame($plan->id()->toRfc4122(), $value['planId']);
        self::assertSame('sales', $value['kind']);
        self::assertSame($plan->dateTo()->format('Y-m-d'), $value['dateTo']);
        $changed = $action($plan->companyId()->toRfc4122(), $plan->id()->toRfc4122(), $plan->version(), [
            new SalesPlanCalendarInput($sales->id(), new PlannedCalendarEvent(SalesPlanCalendarKind::Sales, null, $plan->dateFrom(), $plan->dateTo()->modify('-1 day'))),
            new SalesPlanCalendarInput(null, new PlannedCalendarEvent(SalesPlanCalendarKind::ReplenishmentStop, null, $plan->dateTo(), $plan->dateTo())),
        ], $actor);
        self::assertSame(SetSalesPlanCalendarOutcome::Saved, $changed->outcome);
        $current = $this->calendar()->entriesForUpdate($plan->companyId()->toRfc4122(), $plan->id());
        self::assertCount(2, $current);
        $currentSales = array_values(array_filter($current, static fn ($entry): bool => SalesPlanCalendarKind::Sales === $entry->event()->kind))[0];
        self::assertTrue($sales->id()->equals($currentSales->id()));
        self::assertSame($plan->dateTo()->modify('-1 day')->format('Y-m-d'), $currentSales->event()->dateTo->format('Y-m-d'));
        self::assertSame(1, $this->countAudit($plan, 'sales_plan.calendar_changed'));
        self::assertSame(1, $this->countAudit($plan, 'sales_plan.calendar_removed'));
        self::assertSame(7, $this->connection()->fetchOne('SELECT SUM(quantity) FROM sales_plan_line WHERE company_id = ? AND sales_plan_id = ?', [$plan->companyId()->toRfc4122(), $plan->id()->toRfc4122()]));
    }

    public function testValidationAndTenantGuardsDoNotChangeVersionOrAudit(): void
    {
        self::bootKernel();
        $plan = $this->plan();
        $action = $this->action();
        $event = new PlannedCalendarEvent(SalesPlanCalendarKind::Sales, null, $plan->dateFrom(), $plan->dateTo());
        $input = new SalesPlanCalendarInput(null, $event);
        $company = $plan->companyId()->toRfc4122();
        $id = $plan->id()->toRfc4122();
        $actor = Uuid::v7()->toRfc4122();
        $version = $plan->version();
        self::assertSame(SetSalesPlanCalendarOutcome::NotFound, $action(Uuid::v7()->toRfc4122(), $id, $version, [$input], $actor)->outcome);
        self::assertSame(SetSalesPlanCalendarOutcome::VersionConflict, $action($company, $id, $version + 1, [$input], $actor)->outcome);
        self::assertSame(SetSalesPlanCalendarOutcome::InvalidEntries, $action($company, $id, $version, array_fill(0, 501, $input), $actor)->outcome);
        self::assertSame(SetSalesPlanCalendarOutcome::InvalidEntries, $action($company, $id, $version, [$input, $input], $actor)->outcome);
        self::assertSame(SetSalesPlanCalendarOutcome::InvalidEntries, $action($company, $id, $version, [new SalesPlanCalendarInput(Uuid::v7(), $event)], $actor)->outcome);
        $unknown = new SalesPlanCalendarInput(null, new PlannedCalendarEvent(SalesPlanCalendarKind::Sales, 'OTHER', $plan->dateFrom(), $plan->dateTo()));
        self::assertSame(SetSalesPlanCalendarOutcome::UnknownSku, $action($company, $id, $version, [$unknown], $actor)->outcome);
        self::assertSame($version, $plan->version());
        self::assertSame([], $this->calendar()->entriesForUpdate($company, $plan->id()));
        self::assertSame(0, $this->countAudit($plan, 'sales_plan.calendar_created'));
    }

    public function testOptimisticFailureRollsBackCalendarAndAudit(): void
    {
        self::bootKernel();
        $plan = $this->plan();
        $version = $plan->version();
        $this->connection()->executeStatement('UPDATE sales_plan SET version = version + 1 WHERE company_id = ? AND id = ?', [$plan->companyId()->toRfc4122(), $plan->id()->toRfc4122()]);
        $result = $this->action()($plan->companyId()->toRfc4122(), $plan->id()->toRfc4122(), $version, [
            new SalesPlanCalendarInput(null, new PlannedCalendarEvent(SalesPlanCalendarKind::Sales, null, $plan->dateFrom(), $plan->dateTo())),
        ], Uuid::v7()->toRfc4122());
        self::assertSame(SetSalesPlanCalendarOutcome::VersionConflict, $result->outcome);
        self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM sales_plan_calendar_entry WHERE company_id = ?', [$plan->companyId()->toRfc4122()]));
        self::assertSame(0, $this->countAudit($plan, 'sales_plan.calendar_created'));
    }

    public function testRevisionCopiesCalendarWithNewIdsAndApprovedSourceIsImmutable(): void
    {
        self::bootKernel();
        $plan = $this->plan();
        $company = $plan->companyId()->toRfc4122();
        $actor = Uuid::v7()->toRfc4122();
        self::assertSame(SetSalesPlanCalendarOutcome::Saved, $this->action()($company, $plan->id()->toRfc4122(), $plan->version(), [
            new SalesPlanCalendarInput(null, new PlannedCalendarEvent(SalesPlanCalendarKind::Sales, 'KNOWN', $plan->dateFrom(), $plan->dateTo())),
        ], $actor)->outcome);
        $source = $this->calendar()->entriesForUpdate($company, $plan->id());
        self::assertCount(1, $source);
        $approve = self::getContainer()->get(ApproveSalesPlanAction::class);
        self::assertInstanceOf(ApproveSalesPlanAction::class, $approve);
        self::assertSame(ApproveSalesPlanOutcome::Approved, $approve($company, $plan->id()->toRfc4122(), $plan->version(), $actor)->outcome);
        self::assertSame(SetSalesPlanCalendarOutcome::NotEditable, $this->action()($company, $plan->id()->toRfc4122(), $plan->version(), [], $actor)->outcome);
        $copy = self::getContainer()->get(CreateSalesPlanRevisionAction::class);
        self::assertInstanceOf(CreateSalesPlanRevisionAction::class, $copy);
        $result = $copy($company, $plan->id()->toRfc4122(), $plan->version(), $actor);
        self::assertSame(CreateSalesPlanRevisionOutcome::Created, $result->outcome);
        self::assertNotNull($result->draft);
        $entries = $this->calendar()->entriesForUpdate($company, $result->draft->id());
        self::assertCount(1, $entries);
        self::assertFalse($source[0]->id()->equals($entries[0]->id()));
        self::assertEquals($source[0]->event(), $entries[0]->event());
        self::assertCount(1, $this->calendar()->entriesForUpdate($company, $plan->id()));
    }

    public function testRemoveSkuPrunesItsCalendarWithAuditAndKeepsPlanWideEvents(): void
    {
        self::bootKernel();
        $plan = $this->plan();
        $company = $plan->companyId()->toRfc4122();
        $actor = Uuid::v7()->toRfc4122();
        self::assertSame(SetSalesPlanCalendarOutcome::Saved, $this->action()($company, $plan->id()->toRfc4122(), $plan->version(), [
            new SalesPlanCalendarInput(null, new PlannedCalendarEvent(SalesPlanCalendarKind::Sales, null, $plan->dateFrom(), $plan->dateTo())),
            new SalesPlanCalendarInput(null, new PlannedCalendarEvent(SalesPlanCalendarKind::Replenishment, 'KNOWN', $plan->dateFrom(), $plan->dateFrom())),
        ], $actor)->outcome);
        $remove = self::getContainer()->get(RemoveSalesPlanSkuAction::class);
        self::assertInstanceOf(RemoveSalesPlanSkuAction::class, $remove);
        self::assertSame(EditSalesPlanOutcome::Saved, $remove($company, $plan->id()->toRfc4122(), $plan->version(), 'KNOWN', $actor)->outcome);
        $remaining = $this->calendar()->entriesForUpdate($company, $plan->id());
        self::assertCount(1, $remaining);
        self::assertNull($remaining[0]->event()->marketplaceSku);
        self::assertSame(1, $this->countAudit($plan, 'sales_plan.calendar_removed'));
    }

    public function testFiveHundredEventsPageWithoutLossAndUnchangedSaveKeepsIdsWithoutFalseAudit(): void
    {
        self::bootKernel();
        $plan = $this->plan();
        $company = $plan->companyId()->toRfc4122();
        $id = $plan->id()->toRfc4122();
        $actor = Uuid::v7()->toRfc4122();
        $inputs = [];
        foreach ([null, 'KNOWN'] as $sku) {
            for ($from = 0; $from < 31 && \count($inputs) < 500; ++$from) {
                for ($to = $from; $to < 31 && \count($inputs) < 500; ++$to) {
                    $inputs[] = new SalesPlanCalendarInput(null, new PlannedCalendarEvent(SalesPlanCalendarKind::Sales, $sku, $plan->dateFrom()->modify('+'.$from.' days'), $plan->dateFrom()->modify('+'.$to.' days')));
                }
            }
        }
        self::assertCount(500, $inputs);
        self::assertSame(SetSalesPlanCalendarOutcome::Saved, $this->action()($company, $id, $plan->version(), $inputs, $actor)->outcome);
        $query = new ReadSalesPlanCalendarAction(new SalesPlanCalendarQuery($this->connection()));
        $default = $query($company, $id);
        self::assertNotNull($default);
        self::assertCount(50, $default->entries);
        $ids = [];
        $cursor = null;
        foreach ([200, 200, 100] as $expected) {
            $page = $query($company, $id, 200, $cursor);
            self::assertNotNull($page);
            self::assertCount($expected, $page->entries);
            foreach ($page->entries as $entry) {
                $ids[] = $entry->id;
            }
            $cursor = $page->nextCursor;
        }
        self::assertNull($cursor);
        self::assertCount(500, array_unique($ids));
        self::assertNull($query(Uuid::v7()->toRfc4122(), $id));
        $existing = $this->calendar()->entriesForUpdate($company, $plan->id());
        $unchanged = array_map(static fn ($entry): SalesPlanCalendarInput => new SalesPlanCalendarInput($entry->id(), $entry->event()), $existing);
        self::assertSame(SetSalesPlanCalendarOutcome::Saved, $this->action()($company, $id, $plan->version(), $unchanged, $actor)->outcome);
        self::assertSame(500, $this->countAudit($plan, 'sales_plan.calendar_created'));
        self::assertSame(0, $this->countAudit($plan, 'sales_plan.calendar_changed'));
        self::assertSame(0, $this->countAudit($plan, 'sales_plan.calendar_removed'));
        self::assertSame(SetSalesPlanCalendarOutcome::Saved, $this->action()($company, $id, $plan->version(), [], $actor)->outcome);
        self::assertSame([], $this->calendar()->entriesForUpdate($company, $plan->id()));
        self::assertSame(500, $this->countAudit($plan, 'sales_plan.calendar_removed'));
    }

    public function testRemoveSkuOptimisticFailurePreservesCalendarLinesAndAudit(): void
    {
        self::bootKernel();
        $plan = $this->plan();
        $company = $plan->companyId()->toRfc4122();
        $id = $plan->id()->toRfc4122();
        $actor = Uuid::v7()->toRfc4122();
        self::assertSame(SetSalesPlanCalendarOutcome::Saved, $this->action()($company, $id, $plan->version(), [
            new SalesPlanCalendarInput(null, new PlannedCalendarEvent(SalesPlanCalendarKind::Replenishment, 'KNOWN', $plan->dateFrom(), $plan->dateFrom())),
        ], $actor)->outcome);
        $this->connection()->executeStatement('UPDATE sales_plan SET version = version + 1 WHERE company_id = ? AND id = ?', [$company, $id]);
        $remove = self::getContainer()->get(RemoveSalesPlanSkuAction::class);
        self::assertInstanceOf(RemoveSalesPlanSkuAction::class, $remove);
        self::assertSame(EditSalesPlanOutcome::VersionConflict, $remove($company, $id, $plan->version(), 'KNOWN', $actor)->outcome);
        self::assertSame(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM sales_plan_calendar_entry WHERE company_id = ? AND sales_plan_id = ?', [$company, $id]));
        self::assertSame(7, $this->connection()->fetchOne('SELECT SUM(quantity) FROM sales_plan_line WHERE company_id = ? AND sales_plan_id = ?', [$company, $id]));
        self::assertSame(0, $this->countAudit($plan, 'sales_plan.calendar_removed'));
        self::assertSame(0, $this->countAudit($plan, 'sales_plan.sku_removed'));
    }

    private function action(): SetSalesPlanCalendarAction
    {
        $identity = self::getContainer()->get(IdentityFacade::class);
        self::assertInstanceOf(IdentityFacade::class, $identity);

        return new SetSalesPlanCalendarAction($this->plans(), $this->calendar(), $identity);
    }

    private function plan(): SalesPlan
    {
        $plan = SalesPlanBuilder::aSalesPlan()->withMetric(SalesPlanMetric::RetainedSales)->build();
        $this->plans()->add($plan, SalesPlanLineBuilder::linesOf($plan)->withMarketplaceSku('KNOWN')->withTotalQuantity(7)->build());

        return $plan;
    }

    private function plans(): SalesPlanRepository
    {
        $repository = self::getContainer()->get(SalesPlanRepository::class);
        self::assertInstanceOf(SalesPlanRepository::class, $repository);

        return $repository;
    }

    private function calendar(): DoctrineSalesPlanCalendarRepository
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return new DoctrineSalesPlanCalendarRepository($manager);
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function countAudit(SalesPlan $plan, string $action): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM audit_record WHERE company_id = ? AND action = ?', [$plan->companyId()->toRfc4122(), $action]);
        self::assertIsInt($count);

        return $count;
    }
}
