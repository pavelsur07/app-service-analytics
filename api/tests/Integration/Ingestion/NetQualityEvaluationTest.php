<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\NetForecast as Net;
use App\Tests\Support\Ingestion\NetQualityForwardFixture;
use App\Tests\Support\Ingestion\NetQualityProbeConnection;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

#[SkipDatabaseRollback]
final class NetQualityEvaluationTest extends KernelTestCase
{
    public function testRealArtifactPipelineScoresExactTruthAndDoesNotTurnOneCaseIntoQualityPass(): void
    {
        self::bootKernel(['debug' => false]);
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $params = $connection->getParams();
        $params['wrapperClass'] = NetQualityProbeConnection::class;
        $db = DriverManager::getConnection($params);
        self::assertInstanceOf(NetQualityProbeConnection::class, $db);
        /** @var Net\AggregateNetForecastAction $aggregate */
        $aggregate = self::getContainer()->get(Net\AggregateNetForecastAction::class);
        $f = new NetQualityForwardFixture($db, $aggregate);
        try {
            $selection = $f->selection();
            $pending = $f->truth(7);
            $missingInventory = $f->truthInventory([$pending]);
            $f->targets->returned('TARGET', 'SKU', 1, $f->targets->readAt->modify('+10 days'));
            $ready = $f->truth(70);
            $inventory = $f->truthInventory([$pending, $ready]);
            $evaluate = new Net\EvaluateNetForecastQualityAction($f->artifacts->reader, new Net\NetQualityProtocolReader($f->artifacts->reader), new Net\NetQualityInventoryReader($f->artifacts->reader), new Net\NetQualityTruthReader($f->artifacts->reader, new Net\NetForwardInputCodec()), $f->artifacts->writer, new Net\NetQualityMetrics(), new Net\NetQualityDecision(), new Net\NetForwardManifestCodec(), new Net\NetQualityReportCodec(), '123-aaaaaaa');
            $blocked = Uuid::v7()->toRfc4122();
            try {
                $evaluate($f->protocol->companyId, $f->protocol->accountId, $selection, $inventory, $blocked, new Net\NetQualityBudget(maxSpoolBytes: 1));
                self::fail('Spool overflow must abort the evaluation.');
            } catch (\OverflowException) {
                $repository = new \App\Ingestion\Infrastructure\Persistence\DbalNetForecastArtifactRepository($db);
                self::assertNull($repository->find($f->protocol->companyId, $f->protocol->accountId, \App\Ingestion\Domain\NetForwardArtifactKind::QualityDataset, $blocked));
                self::assertNull($repository->find($f->protocol->companyId, $f->protocol->accountId, \App\Ingestion\Domain\NetForwardArtifactKind::QualityReport, $blocked));
            }
            $id = Uuid::v7()->toRfc4122();
            $db->forbidSourceQueries = true;
            $result = $evaluate($f->protocol->companyId, $f->protocol->accountId, $selection, $inventory, $id);
            self::assertSame('INSUFFICIENT_DATA', $result->decision);
            self::assertSame(1, $result->counts['overall']);
            $again = $evaluate($f->protocol->companyId, $f->protocol->accountId, $selection, $inventory, $id);
            self::assertSame($result->datasetRef->sha256, $again->datasetRef->sha256);
            self::assertSame($result->reportRef->sha256, $again->reportRef->sha256);
            $protocols = new Net\NetQualityProtocolReader($f->artifacts->reader);
            $inventories = new Net\NetQualityInventoryReader($f->artifacts->reader);
            $repository = new \App\Ingestion\Infrastructure\Persistence\DbalNetForecastArtifactRepository($db);
            $command = new \App\Ingestion\Ui\Command\EvaluateNetForecastQualityCommand(
                new Net\FreezeNetQualityProtocolAction($f->artifacts->writer, $f->artifacts->reader, $repository, $f->artifacts->clock, '123-aaaaaaa'),
                new Net\SealNetQualityInventoryAction($f->artifacts->writer, $f->artifacts->reader, $protocols, $repository, $f->artifacts->clock),
                new Net\SelectNetQualityObservationsAction($f->artifacts->reader, $inventories, $protocols, new Net\NetQualityInputReader($f->artifacts->reader, new Net\NetForwardInputCodec()), new Net\NetQualitySelection(), $f->artifacts->writer),
                $evaluate,
                $inventories,
            );
            $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
            self::assertSame(0, $tester->execute(['mode' => 'evaluate', '--company' => $f->protocol->companyId, '--account' => $f->protocol->accountId, '--operation' => $id, '--selection' => $selection->requestId, '--selection-hash' => $selection->sha256, '--truth-inventory' => $inventory->reference->requestId, '--truth-inventory-hash' => $inventory->reference->sha256]));
            self::assertStringContainsString('INSUFFICIENT_DATA', $tester->getDisplay());
            self::assertStringNotContainsString('TARGET', $tester->getDisplay());
            self::assertStringNotContainsString('SKU', $tester->getDisplay());
            [, $report] = (new Net\NetQualityArtifactPayloadReader($f->artifacts->reader))->read($f->protocol->companyId, $f->protocol->accountId, $result->reportRef, 'quality_report', new Net\NetQualityBudget());
            self::assertFalse($report['numericRolloutAllowed']);
            $f->artifacts->storage->failReportWrites = true;
            $partial = Uuid::v7()->toRfc4122();
            try {
                $evaluate($f->protocol->companyId, $f->protocol->accountId, $selection, $inventory, $partial);
                self::fail('Report upload failure cannot return a completed evaluation.');
            } catch (\RuntimeException) {
                $repository = new \App\Ingestion\Infrastructure\Persistence\DbalNetForecastArtifactRepository($db);
                $savedDataset = $repository->find($f->protocol->companyId, $f->protocol->accountId, \App\Ingestion\Domain\NetForwardArtifactKind::QualityDataset, $partial);
                self::assertNotNull($savedDataset);
                self::assertNull($repository->find($f->protocol->companyId, $f->protocol->accountId, \App\Ingestion\Domain\NetForwardArtifactKind::QualityReport, $partial));
                $f->artifacts->storage->failReportWrites = false;
                $recovered = $evaluate($f->protocol->companyId, $f->protocol->accountId, $selection, $inventory, $partial);
                self::assertSame($savedDataset->root->sha256, $recovered->datasetRef->sha256);
            }
            $missing = $evaluate($f->protocol->companyId, $f->protocol->accountId, $selection, $missingInventory, Uuid::v7()->toRfc4122());
            self::assertSame('INVALID_EVIDENCE', $missing->decision);
            self::assertIsArray($report['groups']);
            $overall = $report['groups']['overall'];
            self::assertIsArray($overall);
            self::assertIsArray($overall['metrics']);
            self::assertIsArray($overall['metrics']['method']);
            self::assertEquals(['numerator' => '0', 'denominator' => '1'], $overall['metrics']['method']['mae']);
        } finally {
            $db->forbidSourceQueries = false;
            $f->cleanup();
            $db->close();
        }
    }
}
