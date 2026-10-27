<?php

declare(strict_types=1);

namespace App\Tests\Unit\SalesPlanning\Domain;

use App\SalesPlanning\Domain\SalesPlanMetric;
use App\Tests\Support\Builder\SalesPlanBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class SalesPlanMetricTest extends TestCase
{
    public function testLegacyDraftHasNoMetricAndFirstDraftCanExplicitlyChangeIt(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()->build();
        self::assertNull($plan->metric());
        $at = new \DateTimeImmutable('2026-07-01 12:00:00');
        $plan->selectMetric(SalesPlanMetric::RetainedSales, $at);
        self::assertSame(SalesPlanMetric::RetainedSales, $plan->metric());
        self::assertSame($at, $plan->updatedAt());
        $plan->selectMetric(SalesPlanMetric::OrdersExcludingPreHandoverCancellations, $at);
        self::assertSame(SalesPlanMetric::OrdersExcludingPreHandoverCancellations, $plan->metric());
    }

    public function testApprovalRequiresExplicitMetric(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()->build();
        $this->expectException(\DomainException::class);
        $plan->approve(Uuid::v7(), new \DateTimeImmutable());
    }

    public function testApprovedMetricCannotBeChanged(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()->build();
        $at = new \DateTimeImmutable();
        $plan->selectMetric(SalesPlanMetric::RetainedSales, $at);
        $plan->approve(Uuid::v7(), $at);
        $this->expectException(\DomainException::class);
        $plan->selectMetric(SalesPlanMetric::OrdersExcludingPreHandoverCancellations, $at);
    }

    public function testInheritedTypedMetricCannotChangeInSameLineage(): void
    {
        $source = SalesPlanBuilder::aSalesPlan()->withMetric(SalesPlanMetric::RetainedSales)->build();
        $source->approve(Uuid::v7(), new \DateTimeImmutable());
        $plan = SalesPlanBuilder::revisionOf($source)->build();
        $plan->selectMetric(SalesPlanMetric::RetainedSales, new \DateTimeImmutable());
        self::assertSame(SalesPlanMetric::RetainedSales, $plan->metric());
        $this->expectException(\DomainException::class);
        $plan->selectMetric(SalesPlanMetric::OrdersExcludingPreHandoverCancellations, new \DateTimeImmutable());
    }
}
