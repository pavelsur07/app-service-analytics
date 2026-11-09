<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\StoreOzonCampaignCatalog;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Infrastructure\Query\OzonCampaignCatalogQuery;
use App\Tests\Support\Builder\MarketplaceRawDocumentBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class RebuildOzonCampaignCatalogCommandTest extends KernelTestCase
{
    public function testRebuildUsesScopedSavedRawAndDoesNotNeedLiveCredentials(): void
    {
        $kernel = self::bootKernel();
        $container = self::getContainer();
        $body = file_get_contents(__DIR__.'/../../Fixtures/Marketplace/ozon/performance/campaign-list.json');
        self::assertIsString($body);
        $raw = MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()->withReportType(MarketplaceReportType::OzonAdCampaigns)->withRawBody($body)->persistWith(MarketplaceRawDocumentBuilder::repository($container));
        $tester = new CommandTester((new Application($kernel))->find('app:ingestion:rebuild-ozon-campaign-catalog'));
        self::assertSame(1, $tester->execute(['companyId' => Uuid::v7()->toRfc4122(), 'accountId' => $raw->marketplaceAccountId()->toRfc4122()]));
        self::assertSame(0, $tester->execute(['companyId' => $raw->companyId()->toRfc4122(), 'accountId' => $raw->marketplaceAccountId()->toRfc4122()]));
        $query = $container->get(OzonCampaignCatalogQuery::class);
        self::assertInstanceOf(OzonCampaignCatalogQuery::class, $query);
        $row = $query->build($raw->companyId()->toRfc4122(), $raw->marketplaceAccountId()->toRfc4122(), null, 20, true)->executeQuery()->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame(94, $row['total']);
        self::assertTrue($row['restored']);
        self::assertSame(2, $tester->execute(['companyId' => 'invalid', 'accountId' => $raw->marketplaceAccountId()->toRfc4122()]));
    }

    public function testRebuildCannotReplaceAnObservationAndLabelsLostRepeatHistoryAsUnverified(): void
    {
        $kernel = self::bootKernel();
        $container = self::getContainer();
        $store = $container->get(StoreOzonCampaignCatalog::class);
        $query = $container->get(OzonCampaignCatalogQuery::class);
        $db = $container->get(Connection::class);
        self::assertInstanceOf(StoreOzonCampaignCatalog::class, $store);
        self::assertInstanceOf(OzonCampaignCatalogQuery::class, $query);
        self::assertInstanceOf(Connection::class, $db);
        $base = MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()->withReportType(MarketplaceReportType::OzonAdCampaigns);
        $aBody = '{"list":[{"id":"1","title":"A","state":"CAMPAIGN_STATE_RUNNING","advObjectType":"SKU","PaymentType":"CPC"}],"total":"1"}';
        $bBody = str_replace('"A"', '"B"', $aBody);
        $rawRepository = MarketplaceRawDocumentBuilder::repository($container);
        $a = $base->withReceivedAt(new \DateTimeImmutable('2026-11-09T10:00:00Z'))->withRawBody($aBody)->persistWith($rawRepository);
        $b = $base->withReceivedAt(new \DateTimeImmutable('2026-11-09T11:00:00Z'))->withRawBody($bBody)->persistWith($rawRepository);
        $company = $a->companyId()->toRfc4122();
        $account = $a->marketplaceAccountId();
        // Same raw A observed again after B; its new receipt exists only in the projection.
        $store->store($company, $account, $a->id(), new \DateTimeImmutable('2026-11-09T12:00:00Z'), $aBody);
        $tester = new CommandTester((new Application($kernel))->find('app:ingestion:rebuild-ozon-campaign-catalog'));
        $arguments = ['companyId' => $company, 'accountId' => $account->toRfc4122()];
        self::assertSame(0, $tester->execute($arguments));
        $row = $query->build($company, $account->toRfc4122(), null, 50, true)->executeQuery()->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame($a->id()->toRfc4122(), $row['raw_document_id']);
        self::assertFalse($row['restored']);
        $db->executeStatement('DELETE FROM ozon_campaign_catalog_snapshot WHERE company_id=? AND account_id=?', [$company, $account->toRfc4122()]);
        self::assertSame(0, $tester->execute($arguments));
        $row = $query->build($company, $account->toRfc4122(), null, 50, true)->executeQuery()->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame($b->id()->toRfc4122(), $row['raw_document_id']);
        self::assertTrue($row['restored']);
        $store->store($company, $account, $a->id(), new \DateTimeImmutable('2026-11-09T13:00:00Z'), $aBody);
        $row = $query->build($company, $account->toRfc4122(), null, 50, true)->executeQuery()->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame($a->id()->toRfc4122(), $row['raw_document_id']);
        self::assertFalse($row['restored']);
    }
}
