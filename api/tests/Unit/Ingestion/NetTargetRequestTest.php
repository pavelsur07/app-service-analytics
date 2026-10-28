<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetLimits;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetRequest;
use PHPUnit\Framework\TestCase;

final class NetTargetRequestTest extends TestCase
{
    public function testExactDistinctSkuAndAccumulationCalendarBounds(): void
    {
        $from = new \DateTimeImmutable('2026-06-01 19:30:00+03:00');
        $request = new NetTargetRequest(['SKU', 'тест'], $from, new \DateTimeImmutable('2026-07-03'), new \DateTimeImmutable('2026-01-01'));
        self::assertSame(['SKU', 'тест'], $request->marketplaceSkus);
        self::assertSame('2026-06-01T00:00:00+00:00', $request->from->format('c'));
        self::assertSame('2026-01-01', $request->accumulationFrom?->format('Y-m-d'));
        self::assertSame([], (new NetTargetRequest([], $from, $from))->marketplaceSkus);
    }

    public function testInvalidScopesAndOverlongAccumulationFailBeforeSql(): void
    {
        $day = new \DateTimeImmutable('2026-01-01');
        /** @var list<list<string>> $invalidScopes Deliberately hostile runtime input. */
        $invalidScopes = [['X', 'X'], [''], [123], [null], ["X\n"], ["\xFF"], [str_repeat('x', 65)], array_map(strval(...), range(1, 51))];
        foreach ($invalidScopes as $skus) {
            try {
                new NetTargetRequest($skus, $day, $day);
                self::fail('Invalid source scope was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        foreach ([['2026-01-02', '2026-01-01', null], ['2026-01-01', '2026-07-04', null], ['2026-01-02', '2026-01-03', '2026-01-03']] as [$from, $to, $accumulation]) {
            try {
                new NetTargetRequest(['X'], new \DateTimeImmutable($from), new \DateTimeImmutable($to), null === $accumulation ? null : new \DateTimeImmutable($accumulation));
                self::fail('Invalid calendar bounds were accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testLimitsCanOnlyReduceOperationalCaps(): void
    {
        self::assertSame(1, (new NetTargetLimits(sourceKeys: 1))->sourceKeys);
        foreach ([0, -1, 50001] as $invalid) {
            try {
                new NetTargetLimits(sourceKeys: $invalid);
                self::fail('Invalid operational limit.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new NetTargetLimits(computeSeconds: \INF);
    }
}
