<?php

declare(strict_types=1);

namespace App\Tests\Unit\Planning;

use App\Planning\Domain\DailyPlan;
use App\Tests\Support\Builder\DailyPlanBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class DailyPlanTest extends TestCase
{
    public function testQuantityCanBeChangedAndRemovedWithoutChangingItsKey(): void
    {
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $actorId = Uuid::v7();
        $date = new \DateTimeImmutable('2026-09-21 15:30:00', new \DateTimeZone('Europe/Moscow'));
        $createdAt = new \DateTimeImmutable('2026-09-20 10:00:00');
        $plan = DailyPlanBuilder::aDailyPlan()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withMarketplaceSku('SKU-1')
            ->withBusinessDate($date)
            ->withQuantity(12)
            ->withUpdatedBy($actorId)
            ->withCreatedAt($createdAt)
            ->build();

        self::assertSame('2026-09-21', $plan->businessDate()->format('Y-m-d'));
        self::assertSame(12, $plan->quantity());
        self::assertSame(1, $plan->version());

        $plan->changeQuantity(0, $actorId, $createdAt->modify('+1 hour'));
        self::assertSame(0, $plan->quantity());

        $plan->remove($actorId, $createdAt->modify('+2 hours'));
        self::assertNull($plan->quantity());
        self::assertSame('SKU-1', $plan->marketplaceSku());
        self::assertSame('2026-09-21', $plan->businessDate()->format('Y-m-d'));
    }

    public function testNegativeQuantityIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DailyPlanBuilder::aDailyPlan()->withQuantity(-1)->build();
    }

    public function testQuantityAboveDatabaseRangeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DailyPlanBuilder::aDailyPlan()->withQuantity(DailyPlan::MAX_QUANTITY + 1)->build();
    }

    public function testInvalidUtf8SkuIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DailyPlanBuilder::aDailyPlan()->withMarketplaceSku("\xFF")->build();
    }
}
