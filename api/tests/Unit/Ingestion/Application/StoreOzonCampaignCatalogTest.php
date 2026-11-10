<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application;

use App\Ingestion\Application\StoreOzonCampaignCatalog;
use App\Ingestion\Domain\OzonCampaignCatalogParser;
use App\Ingestion\Domain\OzonCampaignCatalogRepository;
use App\Shared\Domain\ExceptionTracker;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

final class StoreOzonCampaignCatalogTest extends TestCase
{
    public function testRejectedMetadataIsTrackedWithoutSendingTheResponseOrReplacingTheCatalog(): void
    {
        $repository = self::createMock(OzonCampaignCatalogRepository::class);
        $repository->expects(self::never())->method('replace');
        $errors = self::createMock(ExceptionTracker::class);
        $errors->expects(self::once())->method('capture')->with(self::callback(static fn (\Throwable $failure): bool => $failure instanceof \UnexpectedValueException
            && 'Saved Ozon campaign catalog could not be parsed.' === $failure->getMessage() && null === $failure->getPrevious()));
        $store = new StoreOzonCampaignCatalog(new OzonCampaignCatalogParser(), $repository, new NullLogger(), $errors);
        self::assertFalse($store->store(Uuid::v7()->toRfc4122(), Uuid::v7(), Uuid::v7(), new \DateTimeImmutable(), '{"list":[{"id":"1","state":"RUNNING","advObjectType":"SKU","title":{"private":"response-data"}}]}'));
    }
}
