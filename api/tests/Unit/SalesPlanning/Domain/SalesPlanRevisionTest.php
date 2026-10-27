<?php

declare(strict_types=1);

namespace App\Tests\Unit\SalesPlanning\Domain;

use App\SalesPlanning\Domain\SalesPlanMetric;
use App\SalesPlanning\Domain\SalesPlanStatus;
use App\Tests\Support\Builder\SalesPlanBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class SalesPlanRevisionTest extends TestCase
{
    public function testRevisionIsNewDraftWithInheritedGoalAndFreshCreator(): void
    {
        $source = SalesPlanBuilder::aSalesPlan()->withMetric(SalesPlanMetric::RetainedSales)->build();
        $approvedBy = Uuid::v7();
        $approvedAt = new \DateTimeImmutable('2026-06-26 10:00:00');
        $source->approve($approvedBy, $approvedAt);
        $actor = Uuid::v7();
        $at = new \DateTimeImmutable('2026-06-27 12:00:00');
        $revision = $source->newRevision($actor, $at);

        self::assertInstanceOf(\Symfony\Component\Uid\UuidV7::class, $revision->id());
        self::assertFalse($revision->id()->equals($source->id()));
        self::assertSame(SalesPlanStatus::Draft, $revision->status());
        self::assertSame($source->lineageId(), $revision->lineageId());
        self::assertSame($source->companyId(), $revision->companyId());
        self::assertSame($source->marketplaceAccountId(), $revision->marketplaceAccountId());
        self::assertSame($source->name(), $revision->name());
        self::assertSame($source->dateFrom(), $revision->dateFrom());
        self::assertSame($source->dateTo(), $revision->dateTo());
        self::assertSame($source->currency(), $revision->currency());
        self::assertSame($source->metric(), $revision->metric());
        self::assertSame(2, $revision->versionNumber());
        self::assertSame(1, $revision->version());
        self::assertSame($actor, $revision->createdByUserId());
        self::assertSame($at, $revision->createdAt());
        self::assertSame($at, $revision->updatedAt());
        self::assertNull($revision->approvedAt());
        self::assertNull($revision->approvedByUserId());
        self::assertSame(SalesPlanStatus::Approved, $source->status());
        self::assertSame($approvedBy, $source->approvedByUserId());
        self::assertSame($approvedAt, $source->approvedAt());
        self::assertSame($approvedAt, $source->updatedAt());
        self::assertSame(1, $source->versionNumber());
    }

    public function testDraftCannotBecomeRevisionSource(): void
    {
        $source = SalesPlanBuilder::aSalesPlan()->build();
        $this->expectException(\DomainException::class);
        $source->newRevision(Uuid::v7(), new \DateTimeImmutable());
    }

    public function testExplicitNextNumberCanSkipDiscardedVersionsButMustIncrease(): void
    {
        $source = SalesPlanBuilder::aSalesPlan()->withMetric(SalesPlanMetric::RetainedSales)->build();
        $source->approve(Uuid::v7(), new \DateTimeImmutable());
        self::assertSame(4, $source->newRevision(Uuid::v7(), new \DateTimeImmutable(), 4)->versionNumber());
        $this->expectException(\InvalidArgumentException::class);
        $source->newRevision(Uuid::v7(), new \DateTimeImmutable(), 1);
    }
}
