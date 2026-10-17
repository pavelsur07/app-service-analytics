<?php

declare(strict_types=1);

namespace App\Tests\Unit\SalesPlanning\Domain;

use App\SalesPlanning\Domain\SalesPlanLine;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\SalesPlanBuilder;
use App\Tests\Support\Builder\SalesPlanLineBuilder;
use PHPUnit\Framework\TestCase;

final class SalesPlanLineTest extends TestCase
{
    public function testSpreadsPeriodQuantityOverEveryDayAndCopiesPrice(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()
            ->withPeriod(new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-07-04'))
            ->build();
        $price = Money::ofMinor(129_900, 'RUB');

        $lines = SalesPlanLineBuilder::linesOf($plan)
            ->withMarketplaceSku('1988146647')
            ->withTotalQuantity(10)
            ->withPrice($price)
            ->build();

        self::assertSame(
            ['2026-07-01' => 3, '2026-07-02' => 3, '2026-07-03' => 2, '2026-07-04' => 2],
            $this->quantitiesByDay($lines),
        );
        foreach ($lines as $line) {
            self::assertSame('1988146647', $line->marketplaceSku());
            self::assertSame(129_900, $line->price($plan)->minorAmount());
            self::assertSame('RUB', $line->price($plan)->currency());
            self::assertTrue($line->companyId()->equals($plan->companyId()));
            self::assertTrue($line->salesPlanId()->equals($plan->id()));
        }
    }

    public function testSpreadMarksPlanChanged(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()->withCreatedAt(new \DateTimeImmutable('2026-06-25 10:00:00'))->build();

        SalesPlanLineBuilder::linesOf($plan)->withChangedAt(new \DateTimeImmutable('2026-06-25 10:05:00'))->build();

        self::assertSame('2026-06-25 10:05:00', $plan->updatedAt()->format('Y-m-d H:i:s'));
    }

    public function testRejectsPriceInAnotherCurrency(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()->withCurrency('RUB')->build();

        $this->expectException(\InvalidArgumentException::class);

        SalesPlanLineBuilder::linesOf($plan)->withPrice(Money::ofMinor(1_000, 'USD'))->build();
    }

    public function testRejectsZeroPrice(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()->build();

        $this->expectException(\InvalidArgumentException::class);

        SalesPlanLineBuilder::linesOf($plan)->withPrice(Money::ofMinor(0, 'RUB'))->build();
    }

    public function testRejectsBlankSku(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()->build();

        $this->expectException(\InvalidArgumentException::class);

        SalesPlanLineBuilder::linesOf($plan)->withMarketplaceSku(' ')->build();
    }

    public function testChangesOneDayAndMarksPlanChanged(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()->build();
        $lines = SalesPlanLineBuilder::linesOf($plan)->withTotalQuantity(31)->build();
        $day = $lines[14];

        $day->changeTo($plan, 10, Money::ofMinor(99_900, 'RUB'), new \DateTimeImmutable('2026-06-26 09:00:00'));

        self::assertSame(10, $day->quantity());
        self::assertSame(99_900, $day->price($plan)->minorAmount());
        self::assertSame('2026-06-26 09:00:00', $plan->updatedAt()->format('Y-m-d H:i:s'));
    }

    public function testRejectedDayChangeLeavesLineAndPlanUntouched(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()->build();
        $lines = SalesPlanLineBuilder::linesOf($plan)
            ->withTotalQuantity(31)
            ->withChangedAt(new \DateTimeImmutable('2026-06-25 10:05:00'))
            ->build();
        $day = $lines[0];

        try {
            $day->changeTo($plan, -1, Money::ofMinor(99_900, 'RUB'), new \DateTimeImmutable('2026-06-26 09:00:00'));
            self::fail('Отрицательные штуки приняты.');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame(1, $day->quantity());
        self::assertSame('2026-06-25 10:05:00', $plan->updatedAt()->format('Y-m-d H:i:s'));
    }

    public function testRejectsChangeThroughAnotherPlan(): void
    {
        $plan = SalesPlanBuilder::aSalesPlan()->build();
        $other = SalesPlanBuilder::aSalesPlan()->withCompanyId($plan->companyId())->build();
        $lines = SalesPlanLineBuilder::linesOf($plan)->build();

        $this->expectException(\InvalidArgumentException::class);

        $lines[0]->changeTo($other, 5, Money::ofMinor(99_900, 'RUB'), new \DateTimeImmutable('2026-06-26 09:00:00'));
    }

    /**
     * @param list<SalesPlanLine> $lines
     *
     * @return array<string, int>
     */
    private function quantitiesByDay(array $lines): array
    {
        $byDay = [];
        foreach ($lines as $line) {
            $byDay[$line->planDate()->format('Y-m-d')] = $line->quantity();
        }

        return $byDay;
    }
}
