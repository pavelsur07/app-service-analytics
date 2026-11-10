<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\OzonCampaignCatalogData;
use App\Ingestion\Domain\OzonCampaignCatalogItem;
use App\Ingestion\Domain\OzonCampaignCatalogRepository;
use App\Ingestion\Infrastructure\Query\OzonCampaignCatalogQuery;
use App\Tests\Support\Builder\OzonCampaignCatalogSnapshotBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class OzonCampaignCatalogTest extends KernelTestCase
{
    public function testSnapshotIsAtomicTenantScopedAndOlderRetryCannotReplaceNewer(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(OzonCampaignCatalogRepository::class);
        $query = self::getContainer()->get(OzonCampaignCatalogQuery::class);
        self::assertInstanceOf(OzonCampaignCatalogRepository::class, $repository);
        self::assertInstanceOf(OzonCampaignCatalogQuery::class, $query);
        $base = OzonCampaignCatalogSnapshotBuilder::aSnapshot();
        $newer = $base->withReceivedAt(new \DateTimeImmutable('2026-11-09T12:00:00.600000Z'))->persistWith($repository);
        $company = $newer->companyId()->toRfc4122();
        $account = $newer->accountId()->toRfc4122();
        $old = new OzonCampaignCatalogData([new OzonCampaignCatalogItem('7', 'Старая', 'CAMPAIGN_STATE_ARCHIVED', 'SKU', 'CPC')], 1, true);
        $base->withData($old)->withReceivedAt(new \DateTimeImmutable('2026-11-09T12:00:00.400000Z'))->persistWith($repository);
        $row = $query->build($company, $account, null, 20, true)->executeQuery()->fetchAssociative();
        self::assertIsArray($row);
        self::assertIsString($row['items']);
        self::assertStringContainsString('123', $row['items']);
        self::assertStringNotContainsString('Старая', $row['items']);
        self::assertFalse($query->build(Uuid::v7()->toRfc4122(), $account, null, 20, true)->executeQuery()->fetchAssociative());
        self::assertFalse($query->build($company, Uuid::v7()->toRfc4122(), null, 20, true)->executeQuery()->fetchAssociative());
        // Re-observed old raw ID at a later sub-second time is still newer data.
        $base->withData($old)->withReceivedAt(new \DateTimeImmutable('2026-11-09T12:00:00.900000Z'))->persistWith($repository);
        $later = $query->build($company, $account, null, 20, true)->executeQuery()->fetchAssociative();
        self::assertIsArray($later);
        self::assertIsString($later['items']);
        self::assertStringContainsString('7', $later['items']);
        $this->expectException(\InvalidArgumentException::class);
        $repository->replace(Uuid::v7()->toRfc4122(), $newer);
    }

    public function testSqlPaginationOrdersDigitStringsWithoutIntegerOverflowAndFiltersArchive(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(OzonCampaignCatalogRepository::class);
        $query = self::getContainer()->get(OzonCampaignCatalogQuery::class);
        self::assertInstanceOf(OzonCampaignCatalogRepository::class, $repository);
        self::assertInstanceOf(OzonCampaignCatalogQuery::class, $query);
        $items = [new OzonCampaignCatalogItem('100', 'C', 'CAMPAIGN_STATE_RUNNING', 'SKU', 'CPC'), new OzonCampaignCatalogItem('9', 'A', 'CAMPAIGN_STATE_ARCHIVED', 'SKU', 'CPC'), new OzonCampaignCatalogItem('10', 'B', 'CAMPAIGN_STATE_RUNNING', 'SKU', 'CPC'), new OzonCampaignCatalogItem('99999999999999999999', 'D', 'CAMPAIGN_STATE_RUNNING', 'SKU', 'CPC')];
        $snapshot = OzonCampaignCatalogSnapshotBuilder::aSnapshot()->withData(new OzonCampaignCatalogData($items, 4, true))->persistWith($repository);
        $row = $query->build($snapshot->companyId()->toRfc4122(), $snapshot->accountId()->toRfc4122(), '9', 1, false)->executeQuery()->fetchAssociative();
        self::assertIsArray($row);
        self::assertIsString($row['items']);
        $page = json_decode($row['items'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($page);
        self::assertSame(['10', '100'], array_column($page, 'id')); // limit+1, SQL bounded.
    }
}
