<?php

declare(strict_types=1);

namespace App\Tests\Unit\Planning;

use App\Planning\Domain\DailyPlan;
use App\Tests\Support\Builder\PlanChangeBuilder;
use PHPUnit\Framework\TestCase;

final class PlanChangeTest extends TestCase
{
    public function testQuantityOutsideDatabaseRangeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PlanChangeBuilder::aPlanChange()
            ->withOldQuantity(DailyPlan::MAX_QUANTITY + 1)
            ->withOldVersion(1)
            ->build();
    }

    public function testPreviousVersionMustMatchPlanState(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PlanChangeBuilder::aPlanChange()->withOldVersion(2)->build();
    }
}
