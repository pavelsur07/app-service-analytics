<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\NetForecast as Net;
use App\Tests\Support\Builder\NetQualityProtocolBuilder;
use App\Tests\Support\Ingestion\NetQualityArtifactFixture;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[SkipDatabaseRollback]
final class NetQualityProtocolTest extends KernelTestCase
{
    public function testFreezeRejectsPastHoldoutAndCommitWithOnlyTheSameShortPrefix(): void
    {
        self::bootKernel(['debug' => false]);
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $params = $connection->getParams();
        unset($params['wrapperClass']);
        $f = new NetQualityArtifactFixture(DriverManager::getConnection($params));
        $repository = new \App\Ingestion\Infrastructure\Persistence\DbalNetForecastArtifactRepository($f->db);
        $freeze = new Net\FreezeNetQualityProtocolAction($f->writer, $f->reader, $repository, $f->clock, '123-aaaaaaa', str_repeat('a', 40));
        foreach ([NetQualityProtocolBuilder::aProtocol()->with('holdoutFrom', '2026-12-16')->build(), NetQualityProtocolBuilder::aProtocol()->with('holdoutFrom', '2027-01-02')->with('codeCommit', str_repeat('a', 7).str_repeat('b', 33))->build()] as $protocol) {
            try {
                try {
                    $freeze($protocol->companyId, $protocol->accountId, $protocol);
                    self::fail('Past holdout and a partial SHA match must be rejected.');
                } catch (\DomainException) {
                    self::assertNull($repository->find($protocol->companyId, $protocol->accountId, \App\Ingestion\Domain\NetForwardArtifactKind::QualityProtocol, $protocol->protocolId));
                }
            } finally {
                $f->cleanup($protocol->companyId, $protocol->accountId);
            }
        }
        $f->db->close();
    }

    public function testFreezePrecedesCaptureAndProtocolVerificationCannotRunInSnapshot(): void
    {
        self::bootKernel(['debug' => false]);
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $params = $connection->getParams();
        unset($params['wrapperClass']);
        $f = new NetQualityArtifactFixture(DriverManager::getConnection($params));
        $protocol = NetQualityProtocolBuilder::aProtocol()->build();
        try {
            $freeze = new Net\FreezeNetQualityProtocolAction($f->writer, $f->reader, new \App\Ingestion\Infrastructure\Persistence\DbalNetForecastArtifactRepository($f->db), $f->clock, '123-aaaaaaa', str_repeat('a', 40));
            $ref = $freeze($protocol->companyId, $protocol->accountId, $protocol);
            $protocols = new Net\NetQualityProtocolReader($f->reader);
            $request = new Net\NetForwardCaptureRequest($protocol->slots[0]['requestId'], $protocol->planId, $protocol->from, $protocol->to, qualityProtocolId: $ref->requestId, qualityProtocolHash: $ref->sha256);
            $context = $protocols->forCapture($protocol->companyId, $protocol->accountId, $request, $ref, new Net\NetQualityBudget());
            self::assertSame('2027-01-01T00:00:00.000000Z', $context->frozenAt->format('Y-m-d\TH:i:s.u\Z'));
            self::assertSame($ref->sha256, $context->protocolRef->sha256);
            $f->db->beginTransaction();
            $this->expectException(\LogicException::class);
            $protocols->forCapture($protocol->companyId, $protocol->accountId, $request, $ref, new Net\NetQualityBudget());
        } finally {
            if ($f->db->isTransactionActive()) {
                $f->db->rollBack();
            }
            $f->cleanup($protocol->companyId, $protocol->accountId);
            $f->db->close();
        }
    }
}
