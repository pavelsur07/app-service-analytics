<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\NetForecast as Net;
use App\Ingestion\Domain\NetForwardArtifactKind;
use App\Tests\Support\Ingestion\NetQualityArtifactFixture;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

#[SkipDatabaseRollback]
final class NetQualityStagedArtifactTest extends KernelTestCase
{
    public function testPublishingAStagedDatasetDoesNotDuplicateItsPrivateSpool(): void
    {
        self::bootKernel(['debug' => false]);
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $params = $connection->getParams();
        unset($params['wrapperClass']);
        $db = DriverManager::getConnection($params);
        $f = new NetQualityArtifactFixture($db);
        $company = Uuid::v7()->toRfc4122();
        $account = Uuid::v7()->toRfc4122();
        try {
            $budget = new Net\NetQualityBudget(maxSpoolBytes: 1200);
            $spool = new Net\NetForwardSpool($f->codec, qualityBudget: $budget);
            $spool->append('test', ['value' => str_repeat('a', 600)]);
            $spool->descriptors();
            $document = new Net\NetQualityArtifactDocument(NetForwardArtifactKind::QualityProtocol, Uuid::v7()->toRfc4122(), hash('sha256', 'staged'), null, ['schema' => 'net_quality_protocol_v1'], []);
            $ref = $f->writer->publishStaged($company, $account, $document, $spool, $budget);
            $verified = $f->reader->read($company, $account, $ref->kind, $ref->requestId, $ref->sha256);
            $rows = iterator_to_array($f->reader->segments($company, $account, $verified));
            self::assertSame(str_repeat('a', 600), $rows[0]['rows'][0]['value']);
        } finally {
            $f->cleanup($company, $account);
            $db->close();
        }
    }
}
