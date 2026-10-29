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
final class NetQualityTruthTest extends KernelTestCase
{
    public function testTruthKeepsProducerVersionAndSelectsEarliestFullyReadyLabel(): void
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
            $pendingRef = $f->truth(7);
            $f->targets->returned('TARGET', 'SKU', 1, $f->targets->readAt->modify('+10 days'));
            $readyRef = $f->truth(70);
            $root = $f->artifacts->reader->read($f->protocol->companyId, $f->protocol->accountId, $readyRef->kind, $readyRef->requestId, $readyRef->sha256);
            $metadata = $root->manifest['metadata'];
            self::assertIsArray($metadata);
            self::assertSame('123-aaaaaaa', $metadata['softwareRelease'] ?? null);
            $truths = new Net\NetQualityTruthReader($f->artifacts->reader, new Net\NetForwardInputCodec());
            $budget = new Net\NetQualityBudget();
            $pending = $truths->read($f->protocol->companyId, $f->protocol->accountId, $pendingRef, $f->input, $f->context, $budget);
            $ready = $truths->read($f->protocol->companyId, $f->protocol->accountId, $readyRef, $f->input, $f->context, $budget);
            $observation = iterator_to_array((new Net\NetQualityInputReader($f->artifacts->reader, new Net\NetForwardInputCodec()))->observations($f->protocol->companyId, $f->protocol->accountId, $f->input, $f->context, $budget, new Net\NetQualitySelectionDiagnostics()))[0];
            $chosen = $truths->choose($observation, [$ready, $pending]);
            self::assertSame('ready', $chosen->state);
            self::assertSame($readyRef->requestId, $chosen->truthRef?->requestId);
            self::assertSame(['numerator' => '9', 'denominator' => '1'], $chosen->quantity?->toArray());
            self::assertSame('pending', $truths->choose($observation, [$pending])->state);
            $ineligibleParts = $pending->parts;
            foreach ($ineligibleParts as &$part) {
                $part['state'] = 'ineligible';
                $part['reason'] = 'coverage_unavailable';
            }
            unset($part);
            $ineligible = new Net\NetQualityTruthFrame($pendingRef, $f->input, $pending->readAt->modify('-1 second'), $ineligibleParts, $pending->softwareRelease);
            self::assertSame('pending', $truths->choose($observation, [$ineligible, $pending])->state, 'Without a fully-ready label preserve the earliest pending observation.');
            $correctedParts = $ready->parts;
            foreach ($correctedParts as &$part) {
                $part['state'] = 'ineligible';
                $part['quantity'] = null;
                $part['reason'] = 'source_correction';
            }
            unset($part);
            $corrected = new Net\NetQualityTruthFrame($readyRef, $f->input, $ready->readAt->modify('+1 day'), $correctedParts, $ready->softwareRelease);
            $invalidated = $truths->choose($observation, [$corrected, $ready]);
            self::assertSame($readyRef, $invalidated->truthRef, 'Earliest ready stays immutable.');
            self::assertNotEmpty($invalidated->invalidations, 'A later correction must remain visible to the decision.');
        } finally {
            $f->cleanup();
            $db->close();
        }
    }
}
