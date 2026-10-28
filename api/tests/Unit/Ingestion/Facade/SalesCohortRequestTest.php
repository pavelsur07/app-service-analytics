<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Facade;

use App\Ingestion\Application\Facade\SalesCohortGrouping;
use App\Ingestion\Application\Facade\SalesCohortRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SalesCohortRequestTest extends TestCase
{
    /** @param list<string> $skus */
    #[DataProvider('invalidRequests')]
    public function testRejectsInvalidRequests(array $skus, string $from, string $to, string $start, ?string $currency): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SalesCohortRequest($skus, new \DateTimeImmutable($from), new \DateTimeImmutable($to), new \DateTimeImmutable($start), valuationCurrency: $currency);
    }

    /** @return iterable<string, array{list<string>, string, string, string, ?string}> */
    public static function invalidRequests(): iterable
    {
        yield '51 SKUs' => [array_fill(0, 51, 'SKU'), '2026-03-01', '2026-03-01', '2026-03-01', null];
        yield 'blank SKU' => [['  '], '2026-03-01', '2026-03-01', '2026-03-01', null];
        yield 'long SKU' => [[str_repeat('A', 65)], '2026-03-01', '2026-03-01', '2026-03-01', null];
        yield 'reversed visible' => [['A'], '2026-03-02', '2026-03-01', '2026-03-01', null];
        yield 'late accumulation' => [['A'], '2026-03-01', '2026-03-02', '2026-03-02', null];
        yield '185 days' => [['A'], '2026-03-02', '2026-09-01', '2026-03-01', null];
        yield 'unknown currency' => [['A'], '2026-03-01', '2026-03-01', '2026-03-01', 'NOT_CURRENCY'];
    }

    public function testUsesCalendarDatesRatherThanElapsedHoursForThe184DayBound(): void
    {
        $request = new SalesCohortRequest(array_map(strval(...), range(1, 50)),
            new \DateTimeImmutable('2026-03-01 00:01:00 Pacific/Auckland'),
            new \DateTimeImmutable('2026-08-31 23:59:59 America/New_York'),
            new \DateTimeImmutable('2026-03-01 23:59:59 America/New_York'));
        self::assertCount(50, $request->marketplaceSkus);
        self::assertSame(SalesCohortGrouping::Day, $request->groupBy);
        self::assertNull(SalesCohortGrouping::tryFrom('year'));
    }
}
