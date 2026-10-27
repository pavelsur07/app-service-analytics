<?php

declare(strict_types=1);

namespace App\Tests\Unit\SalesPlanning\Domain;

use App\SalesPlanning\Domain\SalesPlanStatus;
use App\Tests\Support\Builder\SalesPlanBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class SalesPlanApprovalTest extends TestCase
{
    public function testApprovalRecordsActorAndTimeAndLocksEditing(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()->withMetric(\App\SalesPlanning\Domain\SalesPlanMetric::RetainedSales)->build();
        $actor = Uuid::v7();
        $at = new \DateTimeImmutable('2026-07-01 12:00:00');
        $from = $plan->dateFrom();
        $currency = $plan->currency();
        self::assertNull($plan->approvedAt());
        self::assertNull($plan->approvedByUserId());
        $plan->approve($actor, $at);
        self::assertSame(SalesPlanStatus::Approved, $plan->status());
        self::assertSame($actor, $plan->approvedByUserId());
        self::assertSame($at, $plan->approvedAt());
        self::assertSame($at, $plan->updatedAt());
        self::assertSame($from, $plan->dateFrom());
        self::assertSame($currency, $plan->currency());
        $this->expectException(\DomainException::class);
        $plan->recordLinesChanged($at);
    }

    public function testApprovedPlanCannotBeApprovedAgain(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()->withMetric(\App\SalesPlanning\Domain\SalesPlanMetric::RetainedSales)->build();
        $plan->approve(Uuid::v7(), new \DateTimeImmutable());
        $this->expectException(\DomainException::class);
        $plan->approve(Uuid::v7(), new \DateTimeImmutable());
    }
}
