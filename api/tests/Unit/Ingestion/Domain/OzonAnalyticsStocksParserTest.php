<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Domain;

use App\Ingestion\Domain\OzonAnalyticsStocksParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Разбор /v1/analytics/stocks на живом ответе кабинета (ADR-034,
 * docs/task/ozon-stocks-research.md).
 */
final class OzonAnalyticsStocksParserTest extends TestCase
{
    private const string FIXTURE = __DIR__.'/../../../Fixtures/Marketplace/ozon/stocks/analytics-stocks-2026-09-26.json';

    public function testParsesEveryRowOfTheLiveResponse(): void
    {
        $facts = $this->parseFixture();

        self::assertCount(1152, $facts);
        // Пара SKU × склад уникальна — это и есть ключ строки дня.
        $keys = array_map(static fn ($fact): string => $fact->sourceRowId(), $facts);
        self::assertCount(1152, array_unique($keys));
        // Сумма доступного сходится с разведкой.
        self::assertSame(1014, array_sum(array_map(static fn ($fact): int => $fact->quantities()->available, $facts)));
    }

    public function testMapsTheFirstRowIncludingPickupPointAndClusterMetrics(): void
    {
        $fact = $this->parseFixture()[0];

        self::assertSame('220279573', $fact->marketplaceSku());
        // ПВЗ — отрицательный warehouse_id, так отдаёт площадка.
        self::assertSame(-1008198, $fact->warehouseId());
        self::assertSame('ПВЗ_1008198', $fact->warehouseName());
        self::assertSame(7, $fact->clusterId());
        self::assertSame('Дальний Восток', $fact->clusterName());
        self::assertSame('220279573|-1008198', $fact->sourceRowId());
        self::assertSame(1, $fact->quantities()->available);
        self::assertSame('0.1250', $fact->adsCluster());
        self::assertSame(16, $fact->idcCluster());
        self::assertSame('DEFICIT', $fact->turnoverGradeCluster());
        self::assertSame('2026-09-26', $fact->snapshotDate()->format('Y-m-d'));
    }

    public function testNoSalesClusterHasNoDaysOfCover(): void
    {
        $withoutCover = array_values(array_filter(
            $this->parseFixture(),
            static fn ($fact): bool => null === $fact->idcCluster(),
        ));

        // 201 строка по кластеру без продаж — дней покрытия у Ozon нет.
        self::assertCount(201, $withoutCover);
    }

    public function testContinuationKeysAreRejectedLoudly(): void
    {
        // Ответ частями дал бы неполный снимок, а неполный снимок —
        // «ноль» там, где остаток есть (ADR-034).
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('cursor');

        (new OzonAnalyticsStocksParser())->parse('{"items":[],"cursor":"next"}', Uuid::v7(), Uuid::v7(), new \DateTimeImmutable('2026-09-26'), Uuid::v7());
    }

    public function testMissingQuantityFailsLoudly(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        (new OzonAnalyticsStocksParser())->parse(
            '{"items":[{"sku":1,"warehouse_id":2,"warehouse_name":"W","cluster_id":3,"cluster_name":"C"}]}',
            Uuid::v7(),
            Uuid::v7(),
            new \DateTimeImmutable('2026-09-26'),
            Uuid::v7(),
        );
    }

    /**
     * @return list<\App\Ingestion\Domain\StockSnapshotFact>
     */
    private function parseFixture(): array
    {
        $body = file_get_contents(self::FIXTURE);
        self::assertIsString($body);

        return (new OzonAnalyticsStocksParser())->parse($body, Uuid::v7(), Uuid::v7(), new \DateTimeImmutable('2026-09-26'), Uuid::v7());
    }
}
