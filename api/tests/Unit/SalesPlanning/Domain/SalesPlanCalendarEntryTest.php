<?php

declare(strict_types=1);

namespace App\Tests\Unit\SalesPlanning\Domain;

use App\SalesPlanning\Domain\PlannedCalendarEvent;
use App\SalesPlanning\Domain\SalesPlanCalendarKind;
use App\SalesPlanning\Domain\SalesPlanMetric;
use App\Tests\Support\Builder\SalesPlanBuilder;
use App\Tests\Support\Builder\SalesPlanCalendarEntryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class SalesPlanCalendarEntryTest extends TestCase
{
    public function testChangePreservesIdentityAndCopyUsesNewIdentityWithoutChangingApprovedSource(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()->withMetric(SalesPlanMetric::RetainedSales)->build();
        $entry = SalesPlanCalendarEntryBuilder::forPlan($plan)->build();
        $id = $entry->id();
        $event = new PlannedCalendarEvent(SalesPlanCalendarKind::Sales, 'SKU', $plan->dateFrom(), $plan->dateTo());
        $entry->change($plan, $event);
        self::assertSame($id, $entry->id());
        self::assertEquals($event, $entry->event());
        $plan->approve(Uuid::v7(), new \DateTimeImmutable());
        $revision = $plan->newRevision(Uuid::v7(), new \DateTimeImmutable());
        $copy = $entry->copyFor($revision);
        self::assertInstanceOf(UuidV7::class, $copy->id());
        self::assertFalse($copy->id()->equals($entry->id()));
        self::assertTrue($copy->salesPlanId()->equals($revision->id()));
        self::assertTrue($entry->salesPlanId()->equals($plan->id()));
        self::assertEquals($entry->event(), $copy->event());
        $copy->change($revision, new PlannedCalendarEvent(SalesPlanCalendarKind::ReplenishmentStop, null, $revision->dateTo(), $revision->dateTo()));
        self::assertEquals($event, $entry->event());
    }

    public function testApprovedCalendarCannotChangeAndFailedChangeDoesNotMutateEvent(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()->withMetric(SalesPlanMetric::RetainedSales)->build();
        $entry = SalesPlanCalendarEntryBuilder::forPlan($plan)->build();
        $before = $entry->event();
        $plan->approve(Uuid::v7(), new \DateTimeImmutable());
        try {
            $entry->change($plan, new PlannedCalendarEvent(SalesPlanCalendarKind::Sales, null, $plan->dateFrom(), $plan->dateTo()));
            self::fail('Approved calendar must be immutable.');
        } catch (\DomainException) {
            self::assertEquals($before, $entry->event());
        }
    }

    public function testAnotherPlanCannotBeUsedToChangeAnEntry(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()->build();
        $entry = SalesPlanCalendarEntryBuilder::forPlan($plan)->build();
        $other = SalesPlanBuilder::aSalesPlan()->withCompanyId($plan->companyId())->build();
        $this->expectException(\InvalidArgumentException::class);
        $entry->change($other, $entry->event());
    }

    public function testCopyCannotMoveAnEntryToAnotherCompany(): void
    {
        $entry = SalesPlanCalendarEntryBuilder::aCalendarEntry()->build();
        $this->expectException(\InvalidArgumentException::class);
        $entry->copyFor(SalesPlanBuilder::aSalesPlan()->build());
    }
}
