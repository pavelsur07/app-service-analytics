<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\MarketplaceDayLoadReceipt;
use App\Ingestion\Domain\MarketplaceReturnFact;
use App\Ingestion\Domain\MarketplaceReturnFactRevision;
use App\Ingestion\Domain\SalesFact;
use App\Ingestion\Domain\SalesFactRevision;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FactRevisionSchemaTest extends KernelTestCase
{
    public function testLegacyDefaultsAndRevisionSchemaAreMapped(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        foreach (['sales_fact', 'marketplace_return_fact'] as $table) {
            self::assertSame('0', $db->fetchOne("SELECT column_default FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :table AND column_name = 'fact_revision'", ['table' => $table]));
            self::assertSame('false', $db->fetchOne("SELECT column_default FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :table AND column_name = 'capture_initial'", ['table' => $table]));
        }
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        foreach ([SalesFact::class, MarketplaceReturnFact::class] as $class) {
            self::assertTrue($em->getClassMetadata($class)->hasField('factRevision'));
        }
        foreach ([SalesFactRevision::class, MarketplaceReturnFactRevision::class] as $class) {
            $metadata = $em->getClassMetadata($class);
            self::assertSame(['companyId', 'marketplaceAccountId', 'sourceRowId', 'factRevision'], $metadata->getIdentifierFieldNames());
            self::assertTrue($metadata->hasField('captureOrigin'));
            self::assertTrue($metadata->hasField('captureIsInitial'));
        }
        $receipt = $em->getClassMetadata(MarketplaceDayLoadReceipt::class);
        self::assertSame('marketplace_day_load_receipt', $receipt->getTableName());
        self::assertTrue($receipt->hasField('captureOrigin'));
        self::assertFalse($receipt->hasField('failure'));
    }
}
