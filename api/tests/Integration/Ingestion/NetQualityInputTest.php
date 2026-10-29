<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\NetForecast as Net;
use App\Tests\Support\Ingestion\NetQualityForwardFixture;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[SkipDatabaseRollback]
final class NetQualityInputTest extends KernelTestCase
{
    public function testActualB15CaptureProvidesExactCaseAndDeliveredMasksWithoutLiveRecapture(): void
    {
        self::bootKernel(['debug' => false]);
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $params = $connection->getParams();
        $params['wrapperClass'] = \App\Tests\Support\Ingestion\NetQualityProbeConnection::class;
        $db = DriverManager::getConnection($params);
        self::assertInstanceOf(\App\Tests\Support\Ingestion\NetQualityProbeConnection::class, $db);
        /** @var Net\AggregateNetForecastAction $aggregate */
        $aggregate = self::getContainer()->get(Net\AggregateNetForecastAction::class);
        $f = new NetQualityForwardFixture($db, $aggregate);
        try {
            $db->forbidSourceQueries = true;
            $reader = new Net\NetQualityInputReader($f->artifacts->reader, new Net\NetForwardInputCodec());
            $stats = new Net\NetQualitySelectionDiagnostics();
            $observations = iterator_to_array($reader->observations($f->protocol->companyId, $f->protocol->accountId, $f->input, $f->context, new Net\NetQualityBudget(), $stats));
            self::assertCount(2, $observations);
            foreach ($observations as $observation) {
                self::assertSame(['TARGET'], $observation->components);
                $parts = $observation->payload['parts'];
                self::assertIsArray($parts);
                self::assertIsArray($parts[0]);
                self::assertSame(['numerator' => '9', 'denominator' => '1'], $parts[0]['model']);
                self::assertSame(['numerator' => '541', 'denominator' => '60'], $parts[0]['simple']);
            }
            $db->forbidSourceQueries = false;
            $f->targets->returned('TARGET', 'SKU', 1, $f->targets->readAt->modify('+10 days'));
            $db->forbidSourceQueries = true;
            $again = iterator_to_array($reader->observations($f->protocol->companyId, $f->protocol->accountId, $f->input, $f->context, new Net\NetQualityBudget(), new Net\NetQualitySelectionDiagnostics()));
            self::assertSame(array_map(static fn (Net\NetQualityObservation $o): array => $o->toArray(), $observations), array_map(static fn (Net\NetQualityObservation $o): array => $o->toArray(), $again));
        } finally {
            $db->forbidSourceQueries = false;
            $f->cleanup();
            $db->close();
        }
    }
}
