<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Infrastructure\Query\OzonCampaignCatalogQuery;
use App\Tests\Support\Builder\MarketplaceRawDocumentBuilder;
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
        self::assertSame(2, $tester->execute(['companyId' => 'invalid', 'accountId' => $raw->marketplaceAccountId()->toRfc4122()]));
    }
}
