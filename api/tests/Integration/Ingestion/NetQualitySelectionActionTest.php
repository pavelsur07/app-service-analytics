<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\NetForecast as Net;
use App\Ingestion\Infrastructure\Persistence\DbalNetForecastArtifactRepository;
use App\Tests\Support\Ingestion\NetQualityForwardFixture;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

#[SkipDatabaseRollback]
final class NetQualitySelectionActionTest extends KernelTestCase
{
    public function testSealedSelectionRetainsBothAgeFamiliesAndOnlyOneOverallCase(): void
    {
        self::bootKernel(['debug' => false]);
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $params = $connection->getParams();
        unset($params['wrapperClass']);
        $db = DriverManager::getConnection($params);
        /** @var Net\AggregateNetForecastAction $aggregate */
        $aggregate = self::getContainer()->get(Net\AggregateNetForecastAction::class);
        $f = new NetQualityForwardFixture($db, $aggregate);
        try {
            $repository = new DbalNetForecastArtifactRepository($db);
            $protocols = new Net\NetQualityProtocolReader($f->artifacts->reader);
            $slots = [];
            foreach ($f->protocol->slots as $slot) {
                $success = $slot['requestId'] === $f->input->requestId;
                $slots[] = ['requestId' => $slot['requestId'], 'status' => $success ? 'success' : 'missing', 'artifact' => $success ? $f->input->toArray() : null, 'reason' => $success ? null : 'not_captured'];
            }
            $cutoff = Net\NetQualitySchema::utc(Net\NetQualitySchema::string($f->protocol->data, 'captureToUtc'));
            $f->artifacts->clock->modify('+'.($cutoff->getTimestamp() - $f->artifacts->clock->now()->getTimestamp() + 1).' seconds');
            $inventory = (new Net\SealNetQualityInventoryAction($f->artifacts->writer, $f->artifacts->reader, $protocols, $repository, $f->artifacts->clock))($f->protocol->companyId, $f->protocol->accountId, new Net\NetQualityInventoryRequest(['requestId' => Uuid::v7()->toRfc4122(), 'protocol' => $f->protocolRef->toArray(), 'subtype' => 'capture', 'cutoffUtc' => $f->protocol->data['captureToUtc'], 'slots' => $slots]));
            $select = new Net\SelectNetQualityObservationsAction($f->artifacts->reader, new Net\NetQualityInventoryReader($f->artifacts->reader), $protocols, new Net\NetQualityInputReader($f->artifacts->reader, new Net\NetForwardInputCodec()), new Net\NetQualitySelection(), $f->artifacts->writer);
            $request = new Net\NetQualitySelectionRequest(Uuid::v7()->toRfc4122(), $f->protocolRef, $inventory);
            $ref = $select($f->protocol->companyId, $f->protocol->accountId, $request);
            self::assertEquals($ref, $select($f->protocol->companyId, $f->protocol->accountId, $request));
            $artifact = $f->artifacts->reader->read($f->protocol->companyId, $f->protocol->accountId, $ref->kind, $ref->requestId, $ref->sha256);
            $groups = [];
            foreach ($f->artifacts->reader->segments($f->protocol->companyId, $f->protocol->accountId, $artifact) as $segment) {
                if ('quality_selection' === $segment['type']) {
                    foreach ($segment['rows'] as $row) {
                        self::assertIsArray($row['value']);
                        $groups[] = $row['value']['group'];
                    }
                }
            }
            sort($groups);
            self::assertSame(['delivery0_6', 'order1_2', 'overall'], $groups);
        } finally {
            $f->cleanup();
            $db->close();
        }
    }
}
