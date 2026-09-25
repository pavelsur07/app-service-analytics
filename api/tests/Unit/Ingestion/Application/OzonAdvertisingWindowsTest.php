<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application;

use App\Ingestion\Application\OzonAdvertisingWindows;
use PHPUnit\Framework\TestCase;

/**
 * Куски загрузки рекламы (ADR-026 п. 4): ни один не длиннее 30 дней —
 * синхронные методы длиннее не проверены, — куски идут встык, без
 * пропусков и наложений, и покрывают ровно заданный период.
 */
final class OzonAdvertisingWindowsTest extends TestCase
{
    public function testTickWindowIsTwoChunksNewestFirst(): void
    {
        $chunks = OzonAdvertisingWindows::lastDays(new \DateTimeImmutable('2026-09-25'), 45);

        self::assertSame([
            ['from' => '2026-08-27', 'to' => '2026-09-25'],
            ['from' => '2026-08-12', 'to' => '2026-08-26'],
        ], $chunks);
    }

    public function testDeepRescanCoversOneHundredEightyFourDaysInSevenChunks(): void
    {
        $chunks = OzonAdvertisingWindows::lastDays(new \DateTimeImmutable('2026-09-25'), 184);

        self::assertCount(7, $chunks);
        self::assertSame('2026-09-25', $chunks[0]['to']);
        // Сегодня минус 183 дня: 184 дня включительно.
        self::assertSame('2026-03-26', $chunks[6]['from']);
        $this->assertContiguousAndShort($chunks);
    }

    public function testInitialLoadReachesTwelveMonthsBack(): void
    {
        $chunks = OzonAdvertisingWindows::initial(new \DateTimeImmutable('2026-09-25'));

        self::assertSame('2026-09-25', $chunks[0]['to']);
        self::assertSame('2025-09-25', $chunks[\count($chunks) - 1]['from']);
        $this->assertContiguousAndShort($chunks);
    }

    public function testSkuDaysAreTodayAndYesterdayInsideTheChunk(): void
    {
        $today = new \DateTimeImmutable('2026-09-25');

        $days = static fn (string $from, string $to): array => array_map(
            static fn (\DateTimeImmutable $day): string => $day->format('Y-m-d'),
            OzonAdvertisingWindows::skuDays(new \DateTimeImmutable($from), new \DateTimeImmutable($to), $today),
        );

        self::assertSame(['2026-09-25', '2026-09-24'], $days('2026-08-27', '2026-09-25'));
        // Кусок кончается вчерашним днём (обработан после полуночи):
        // сегодняшнего дня в нём нет, и спрашивать его нельзя.
        self::assertSame(['2026-09-24'], $days('2026-08-26', '2026-09-24'));
        self::assertSame([], $days('2026-08-12', '2026-08-26'));
    }

    public function testTodayIsTheMoscowDay(): void
    {
        // 22:30 UTC — уже следующий день по Москве: дни Performance API
        // московские, и окно не должно отставать на сутки.
        $today = OzonAdvertisingWindows::today(new \DateTimeImmutable('2026-09-24T22:30:00+00:00'));

        self::assertSame('2026-09-25', $today->format('Y-m-d'));
    }

    /**
     * @param list<array{from: string, to: string}> $chunks
     */
    private function assertContiguousAndShort(array $chunks): void
    {
        foreach ($chunks as $i => $chunk) {
            $from = new \DateTimeImmutable($chunk['from']);
            $to = new \DateTimeImmutable($chunk['to']);
            self::assertLessThanOrEqual(OzonAdvertisingWindows::CHUNK_DAYS, (int) $from->diff($to)->days + 1);

            $next = $chunks[$i + 1] ?? null;
            if (null !== $next) {
                self::assertSame($from->modify('-1 day')->format('Y-m-d'), $next['to']);
            }
        }
    }
}
