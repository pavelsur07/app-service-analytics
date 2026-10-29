<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\NetForecast as Net;
use App\Ingestion\Domain\NetForwardArtifactKind;
use App\Ingestion\Domain\NetForwardArtifactMissing;
use App\Tests\Support\Ingestion\NetQualityArtifactFixture;
use App\Tests\Support\Ingestion\NetQualityProbeConnection;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

#[SkipDatabaseRollback]
final class NetQualityArtifactCacheTest extends KernelTestCase
{
    public function testRepeatedReferencesCacheOnlyScopedAnchorAndStillVerifyEveryObjectRead(): void
    {
        self::bootKernel(['debug' => false]);
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $params = $connection->getParams();
        $params['wrapperClass'] = NetQualityProbeConnection::class;
        $db = DriverManager::getConnection($params);
        self::assertInstanceOf(NetQualityProbeConnection::class, $db);
        $f = new NetQualityArtifactFixture($db);
        $company = Uuid::v7()->toRfc4122();
        $account = Uuid::v7()->toRfc4122();
        try {
            $ref = $f->writer->publish($company, $account, new Net\NetQualityArtifactDocument(NetForwardArtifactKind::QualityProtocol, Uuid::v7()->toRfc4122(), hash('sha256', 'cache'), null, ['schema' => 'net_quality_protocol_v1'], [['type' => 'test', 'row' => ['value' => 'cache']]]), new Net\NetQualityBudget());
            $db->anchorReads = 0;
            $budget = new Net\NetQualityBudget();
            $f->reader->read($company, $account, $ref->kind, $ref->requestId, $ref->sha256, budget: $budget);
            $f->reader->read($company, $account, $ref->kind, $ref->requestId, $ref->sha256, budget: $budget);
            self::assertSame(1, $db->anchorReads);
            self::assertSame(4, $budget->summary()['reads']);
            $this->expectException(NetForwardArtifactMissing::class);
            $f->reader->read($company, Uuid::v7()->toRfc4122(), $ref->kind, $ref->requestId, $ref->sha256, budget: $budget);
        } finally {
            $f->cleanup($company, $account);
            $db->close();
        }
    }
}
