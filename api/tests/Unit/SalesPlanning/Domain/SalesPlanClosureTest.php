<?php

declare(strict_types=1);

namespace App\Tests\Unit\SalesPlanning\Domain;

use App\SalesPlanning\Domain\SalesPlanMetric;
use App\SalesPlanning\Domain\SalesPlanStatus;
use App\Tests\Support\Builder\SalesPlanBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class SalesPlanClosureTest extends TestCase
{
    public function testDiscardKeepsGoalAndLocksDraft(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()->withMetric(SalesPlanMetric::RetainedSales)->build();
        $at = $plan->createdAt();
        $plan->discard($at);
        self::assertSame(SalesPlanStatus::Discarded, $plan->status());
        self::assertSame(SalesPlanMetric::RetainedSales, $plan->metric());
        self::assertNull($plan->approvedAt());
        self::assertSame($at, $plan->updatedAt());
        $this->expectException(\DomainException::class);
        $plan->assertEditable();
    }

    public function testCancelKeepsApprovalMetadataAndRejectsSecondTransition(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()->withMetric(SalesPlanMetric::RetainedSales)->build();
        $actor = Uuid::v7();
        $at = $plan->createdAt();
        $plan->approve($actor, $at);
        $plan->cancel($at);
        self::assertSame(SalesPlanStatus::Cancelled, $plan->status());
        self::assertSame($actor, $plan->approvedByUserId());
        self::assertSame($at, $plan->approvedAt());
        self::assertSame(SalesPlanMetric::RetainedSales, $plan->metric());
        $this->expectException(\DomainException::class);
        $plan->cancel($at);
    }

    public function testCannotCancelDraft(): void
    {
        $this->expectException(\DomainException::class);
        SalesPlanBuilder::aSalesPlan()->build()->cancel(new \DateTimeImmutable());
    }

    public function testCannotDiscardApproved(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()->withMetric(SalesPlanMetric::RetainedSales)->build();
        $plan->approve(Uuid::v7(), new \DateTimeImmutable());
        $this->expectException(\DomainException::class);
        $plan->discard(new \DateTimeImmutable());
    }
}
