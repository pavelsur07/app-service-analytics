<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Infrastructure\Persistence\DoctrineOzonCampaignCatalogRepository;
use App\Ingestion\Infrastructure\Query\OzonCampaignCatalogQuery;
use App\Tests\Support\Builder\OzonCampaignCatalogSnapshotBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/** Own committed synthetic rows are removed in finally (ADR-005). */
final class OzonCampaignCatalogConcurrencyTest extends KernelTestCase
{
    public function testConcurrentOlderWriterWaitsForCommitAndCannotOverwriteSnapshot(): void
    {
        self::bootKernel();
        $application = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $application);
        $db = DriverManager::getConnection($application->getParams());
        $database = $db->fetchOne('SELECT current_database()');
        self::assertIsString($database);
        self::assertMatchesRegularExpression('/\Aapp_test(?:[1-9][0-9]*)?\z/', $database);
        $snapshot = OzonCampaignCatalogSnapshotBuilder::aSnapshot()->withReceivedAt(new \DateTimeImmutable('2026-11-09T11:00:00Z'))->build();
        $company = $snapshot->companyId()->toRfc4122();
        $account = $snapshot->accountId()->toRfc4122();
        self::assertFalse($db->fetchOne('SELECT id FROM company WHERE id=?', [$company]));
        $child = new Process([\PHP_BINARY, __DIR__.'/../../Support/Ingestion/concurrent-campaign-catalog.php'], env: ['APP_ENV' => 'test'], timeout: 15);
        $child->setInput(json_encode(['company' => $company, 'account' => $account, 'database' => $database], \JSON_THROW_ON_ERROR));
        try {
            $db->beginTransaction();
            (new DoctrineOzonCampaignCatalogRepository($db))->replace($company, $snapshot);
            $child->start();
            self::assertTrue($child->waitUntil(static fn () => str_contains($child->getOutput(), '"ready"')));
            $ready = json_decode(trim($child->getOutput()), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($ready);
            self::assertIsInt($ready['pid']);
            $waiting = false;
            $deadline = microtime(true) + 5;
            do {
                $waiting = 'Lock' === $application->fetchOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid=?', [$ready['pid']]);
                if (!$waiting) {
                    usleep(10000);
                }
            } while (!$waiting && microtime(true) < $deadline);
            self::assertTrue($waiting, 'Child must actually overlap the uncommitted writer.');
            $db->commit();
            $child->wait();
            self::assertSame(0, $child->getExitCode(), $child->getErrorOutput());
            $row = (new OzonCampaignCatalogQuery($db))->build($company, $account, null, 20, true)->executeQuery()->fetchAssociative();
            self::assertIsArray($row);
            self::assertIsString($row['items']);
            self::assertStringContainsString('123', $row['items']);
            self::assertStringNotContainsString('Old', $row['items']);
        } finally {
            if ($child->isRunning()) {
                $child->stop(1);
            }
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }
            $db->executeStatement('DELETE FROM ozon_campaign_catalog_snapshot WHERE company_id=? AND account_id=?', [$company, $account]);
            $db->close();
        }
    }
}
