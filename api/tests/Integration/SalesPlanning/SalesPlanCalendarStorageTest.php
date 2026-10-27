<?php

declare(strict_types=1);

namespace App\Tests\Integration\SalesPlanning;

use App\SalesPlanning\Domain\PlannedCalendarEvent;
use App\SalesPlanning\Domain\SalesPlan;
use App\SalesPlanning\Domain\SalesPlanCalendarKind;
use App\SalesPlanning\Domain\SalesPlanCalendarRepository;
use App\SalesPlanning\Domain\SalesPlanRepository;
use App\SalesPlanning\Infrastructure\Persistence\DoctrineSalesPlanCalendarRepository;
use App\Tests\Support\Builder\SalesPlanBuilder;
use App\Tests\Support\Builder\SalesPlanCalendarEntryBuilder;
use App\Tests\Support\Builder\SalesPlanLineBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SalesPlanCalendarStorageTest extends KernelTestCase
{
    public function testOrmRoundTripScopeMembershipAndRemoval(): void
    {
        self::bootKernel();
        $plan = $this->plan();
        $repository = $this->repository();
        $event = new PlannedCalendarEvent(SalesPlanCalendarKind::Sales, 'KNOWN', $plan->dateFrom(), $plan->dateTo());
        $entry = SalesPlanCalendarEntryBuilder::forPlan($plan)->withEvent($event)->build();
        $repository->add($entry);
        $this->manager()->flush();
        $this->manager()->clear();
        $loaded = $repository->entriesForUpdate($plan->companyId()->toRfc4122(), $plan->id());
        self::assertCount(1, $loaded);
        self::assertTrue($entry->id()->equals($loaded[0]->id()));
        self::assertEquals($event, $loaded[0]->event());
        self::assertSame([], $repository->entriesForUpdate(Uuid::v7()->toRfc4122(), $plan->id()));
        self::assertSame([], $repository->entriesForUpdate($plan->companyId()->toRfc4122(), Uuid::v7()));
        self::assertSame([], $repository->entriesForUpdate($plan->companyId()->toRfc4122(), $plan->id(), 'OUTSIDE'));
        self::assertTrue($repository->allSkusExist($plan->companyId()->toRfc4122(), $plan->id(), []));
        self::assertTrue($repository->allSkusExist($plan->companyId()->toRfc4122(), $plan->id(), ['KNOWN']));
        self::assertFalse($repository->allSkusExist($plan->companyId()->toRfc4122(), $plan->id(), ['KNOWN', 'OUTSIDE']));
        self::assertFalse($repository->allSkusExist(Uuid::v7()->toRfc4122(), $plan->id(), ['KNOWN']));
        $repository->remove($loaded[0]);
        $this->manager()->flush();
        self::assertSame([], $repository->entriesForUpdate($plan->companyId()->toRfc4122(), $plan->id()));
    }

    public function testAggregateOverLimitIsRejectedRatherThanSilentlyTruncated(): void
    {
        self::bootKernel();
        $plan = $this->plan();
        $repository = $this->repository();
        for ($i = 0; $i < 501; ++$i) {
            $repository->add(SalesPlanCalendarEntryBuilder::forPlan($plan)->build());
        }
        $this->manager()->flush();
        $this->expectException(\DomainException::class);
        $repository->entriesForUpdate($plan->companyId()->toRfc4122(), $plan->id());
    }

    public function testDatabaseRejectsStoredSeasonBoundaries(): void
    {
        self::bootKernel();
        $plan = $this->plan();
        $entry = SalesPlanCalendarEntryBuilder::forPlan($plan)->build();
        $this->repository()->add($entry);
        $this->manager()->flush();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        try {
            $connection->executeStatement("UPDATE sales_plan_calendar_entry SET kind = 'season_start' WHERE company_id = ? AND id = ?", [$plan->companyId()->toRfc4122(), $entry->id()->toRfc4122()]);
            self::fail('Derived season boundary must not be persisted.');
        } catch (DriverException $invalid) {
            self::assertSame('23514', $invalid->getSQLState());
        }
    }

    private function plan(): SalesPlan
    {
        $plan = SalesPlanBuilder::aSalesPlan()->build();
        $repository = self::getContainer()->get(SalesPlanRepository::class);
        self::assertInstanceOf(SalesPlanRepository::class, $repository);
        $repository->add($plan, SalesPlanLineBuilder::linesOf($plan)->withMarketplaceSku('KNOWN')->build());

        return $plan;
    }

    private function repository(): SalesPlanCalendarRepository
    {
        return new DoctrineSalesPlanCalendarRepository($this->manager());
    }

    private function manager(): EntityManagerInterface
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
