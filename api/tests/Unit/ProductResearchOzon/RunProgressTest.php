<?php

declare(strict_types=1);

namespace App\Tests\Unit\ProductResearchOzon;

use App\ProductResearchOzon\Domain\MonthSlice;
use App\ProductResearchOzon\Domain\ValueObject\RunMonthState;
use App\ProductResearchOzon\Domain\ValueObject\RunProgress;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class RunProgressTest extends TestCase
{
    public function testUnverifiedMonthCannotMasqueradeAsReady(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RunMonthState(new MonthSlice('2026-01', '2026-01-01', '2026-01-31', 31, false), 'ready', null, null, null);
    }

    public function testReadyRequiresEveryFrozenMonthAndStableCanonicalHash(): void
    {
        $month = new RunMonthState(new MonthSlice('2026-01', '2026-01-01', '2026-01-31', 31, false), 'ready', Uuid::v7()->toRfc4122(), 0, null);
        $company = Uuid::v7()->toRfc4122();
        $run = Uuid::v7()->toRfc4122();
        $source = Uuid::v7()->toRfc4122();
        $progress = new RunProgress($company, $run, $source, 'ready', 3, [$month]);
        self::assertSame('ready', $progress->payload()['status']);
        self::assertSame($progress->hash(), (new RunProgress($company, $run, $source, 'ready', 3, [$month]))->hash());
        $this->expectException(\InvalidArgumentException::class);
        new RunProgress($company, $run, $source, 'ready', 3, [$month, $month]);
    }
}
