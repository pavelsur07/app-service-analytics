<?php

declare(strict_types=1);

namespace App\Tests\Integration\SalesPlanning;

use App\SalesPlanning\Domain\SalesPlanRepository;
use App\SalesPlanning\Infrastructure\Query\SalesPlanPerformanceQuery;
use App\Tests\Support\Builder\SalesPlanBuilder;
use App\Tests\Support\Builder\SalesPlanLineBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SalesPlanPerformanceQueryTest extends KernelTestCase
{
    public function testUsesOriginalNumbersAndIncludesRemovedAndNewSkus(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(SalesPlanRepository::class);
        self::assertInstanceOf(SalesPlanRepository::class, $repository);
        $base = SalesPlanBuilder::aSalesPlan()->build();
        $current = SalesPlanBuilder::aSalesPlan()->withCompanyId($base->companyId())->build();
        $repository->add($base, SalesPlanLineBuilder::linesOf($base)->withMarketplaceSku('REMOVED')->withTotalQuantity(31)->build());
        $repository->add($current, SalesPlanLineBuilder::linesOf($current)->withMarketplaceSku('NEW')->withTotalQuantity(999)->build());
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $query = new SalesPlanPerformanceQuery($connection);
        $rows = $query->build($base->companyId()->toRfc4122(), $current->id()->toRfc4122(), $base->id()->toRfc4122(), '2026-07-01', '2026-07-02', '2026-07-01', 'day', null, 50)->executeQuery()->fetchAllAssociative();
        $mapped = array_map(SalesPlanPerformanceQuery::mapRow(...), $rows);
        self::assertSame(['NEW', 'REMOVED'], array_column($mapped, 'marketplaceSku'));
        self::assertSame(0, $mapped[0]->points[0]->periodQuantity);
        self::assertSame(1, $mapped[1]->points[0]->periodQuantity);
        self::assertSame(129900, $mapped[1]->points[0]->periodRevenueMinor);
        self::assertSame(2, $mapped[1]->points[1]->cumulativeQuantity);
        self::assertSame([], $query->build(Uuid::v7()->toRfc4122(), $current->id()->toRfc4122(), $base->id()->toRfc4122(), '2026-07-01', '2026-07-02', '2026-07-01', 'day', null, 50)->executeQuery()->fetchAllAssociative());
    }

    public function testHiddenDaysAndPagingDoNotResetOriginalAccumulation(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(SalesPlanRepository::class);
        self::assertInstanceOf(SalesPlanRepository::class, $repository);
        $plan = SalesPlanBuilder::aSalesPlan()->build();
        $repository->add($plan, array_merge(
            SalesPlanLineBuilder::linesOf($plan)->withMarketplaceSku('A')->withTotalQuantity(31)->build(),
            SalesPlanLineBuilder::linesOf($plan)->withMarketplaceSku('B')->withTotalQuantity(62)->build(),
        ));
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        foreach (['week', 'month', 'period'] as $group) {
            $rows = (new SalesPlanPerformanceQuery($connection))->build($plan->companyId()->toRfc4122(), $plan->id()->toRfc4122(), $plan->id()->toRfc4122(), '2026-07-10', '2026-07-12', '2026-07-01', $group, 'A', 1)->executeQuery()->fetchAllAssociative();
            $row = SalesPlanPerformanceQuery::mapRow($rows[0]);
            self::assertSame('B', $row->marketplaceSku);
            self::assertCount(1, $row->points);
            self::assertSame('2026-07-10', $row->points[0]->bucketFrom);
            self::assertSame('2026-07-12', $row->points[0]->bucketTo);
            self::assertSame(6, $row->points[0]->periodQuantity);
            self::assertSame(24, $row->points[0]->cumulativeQuantity);
            self::assertSame(3117600, $row->points[0]->cumulativeRevenueMinor);
        }
    }

    public function testRejectsUnboundedDenseRangeBeforeAnySql(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->expectException(\InvalidArgumentException::class);
        (new SalesPlanPerformanceQuery($connection))->build(Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), '2026-01-01', '2026-12-31', '2026-01-01', 'day', null, 50);
    }
}
