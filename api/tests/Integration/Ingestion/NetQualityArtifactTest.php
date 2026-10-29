<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\NetForecast as Net;
use App\Ingestion\Domain\NetForwardArtifactKey;
use App\Ingestion\Domain\NetForwardArtifactKind as Kind;
use App\Ingestion\Domain\NetForwardArtifactMissing;
use App\Ingestion\Domain\NetForwardArtifactStorage;
use App\Ingestion\Infrastructure\Persistence\DbalNetForecastArtifactRepository;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[SkipDatabaseRollback]
final class NetQualityArtifactTest extends KernelTestCase
{
    public function testQualityArtifactRetainsWinnerAndAccountsEveryVerificationRead(): void
    {
        self::bootKernel(['debug' => false]);
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $params = $connection->getParams();
        unset($params['wrapperClass']);
        $db = DriverManager::getConnection($params);
        $company = Uuid::v7()->toRfc4122();
        $account = Uuid::v7()->toRfc4122();
        $storage = new class implements NetForwardArtifactStorage {
            /** @var array<string, string> */
            public array $objects = [];

            /** @param (\Closure(int): void)|null $beforeRead */
            public function putContent(string $companyId, string $accountId, NetForwardArtifactKey $key, string $body, ?\Closure $beforeRead = null): void
            {
                $key->assertScope($companyId, $accountId);
                $this->objects[$key->toString()] = $body;
            }

            public function get(string $companyId, string $accountId, NetForwardArtifactKey $key, int $maxBytes): string
            {
                $key->assertScope($companyId, $accountId);

                return $this->objects[$key->toString()] ?? throw new NetForwardArtifactMissing();
            }
        };
        $codec = new Net\NetForwardManifestCodec();
        $repository = new DbalNetForecastArtifactRepository($db);
        $reader = new Net\NetForwardArtifactReader($db, $repository, $storage, $codec);
        $publisher = new Net\PublishNetForecastCaptureAction($repository, $storage, $reader);
        $writer = new Net\NetQualityArtifactWriter($reader, $publisher, $codec, new MockClock('2026-10-29T12:00:00Z'));
        $id = Uuid::v7()->toRfc4122();
        $document = new Net\NetQualityArtifactDocument(Kind::QualityProtocol, $id, hash('sha256', 'first'), null, ['schema' => 'net_quality_protocol_v1'], [['type' => 'quality_protocol', 'row' => ['value' => 'first']]]);
        try {
            $first = $writer->publish($company, $account, $document, new Net\NetQualityBudget());
            self::assertSame(Kind::QualityProtocol, $first->kind);
            self::assertEquals($first, $writer->publish($company, $account, $document, new Net\NetQualityBudget()));
            $budget = new Net\NetQualityBudget();
            $verified = $reader->read($company, $account, $first->kind, $id, $first->sha256, budget: $budget);
            $rows = iterator_to_array($reader->segments($company, $account, $verified, budget: $budget));
            self::assertSame('first', $rows[0]['rows'][0]['value']);
            self::assertSame(3, $budget->summary()['reads'], 'Initial root+chunk verification and subsequent chunk decode share one counter.');
            try {
                $reader->read($company, Uuid::v7()->toRfc4122(), $first->kind, $id, $first->sha256);
                self::fail('A foreign account must not resolve an artifact.');
            } catch (NetForwardArtifactMissing) {
            }
            $this->expectException(\DomainException::class);
            $writer->publish($company, $account, new Net\NetQualityArtifactDocument(Kind::QualityProtocol, $id, hash('sha256', 'changed'), null, ['schema' => 'net_quality_protocol_v1'], []), new Net\NetQualityBudget());
        } finally {
            $db->executeStatement('DELETE FROM net_forecast_artifact WHERE company_id = ? AND marketplace_account_id = ?', [$company, $account]);
            $db->close();
        }
    }
}
