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
            $freeze = new Net\FreezeNetQualityProtocolAction($f->writer, $f->reader, new \App\Ingestion\Infrastructure\Persistence\DbalNetForecastArtifactRepository($f->db), $f->clock, '123-aaaaaaa');
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
