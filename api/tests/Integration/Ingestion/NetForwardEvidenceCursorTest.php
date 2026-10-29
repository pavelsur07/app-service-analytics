<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\NetForecast\AggregateNetForecastAction;
use App\Ingestion\Infrastructure\Query\NetForecast\NetForwardEvidenceQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetRequest;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;
use App\Tests\Support\Ingestion\NetQualityForwardFixture;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[SkipDatabaseRollback]
final class NetForwardEvidenceCursorTest extends KernelTestCase
{
    public function testCursorKeepsExactPagedEvidenceAndClosesAfterAnEarlyStop(): void
    {
        [$db, $f] = $this->fixture();
        try {
            $db->beginTransaction();
            $db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $snapshot = $db->fetchOne('SELECT txid_current_snapshot()::text');
            self::assertIsString($snapshot);
            $context = new NetTrainingContext($f->protocol->companyId, $f->protocol->accountId, $f->targets->readAt, $f->targets->epoch, $snapshot);
            $request = new NetTargetRequest(['SKU'], new \DateTimeImmutable($f->protocol->from), new \DateTimeImmutable($f->protocol->to), new \DateTimeImmutable($f->protocol->from));
            $query = new NetForwardEvidenceQuery($db);
            foreach (['gross', 'returns', 'target'] as $population) {
                $expected = hash_init('sha256');
                $after = null;
                $count = 0;
                do {
                    $rows = NetForwardEvidenceQuery::mapRows($query->build($context->companyId, $context->accountId, $context, $request, $population, $after)->executeQuery());
                    foreach ($rows as $row) {
                        hash_update($expected, $row->cursor."\n");
                        $after = $row->cursor;
                        ++$count;
                    }
                } while (NetForwardEvidenceQuery::PAGE_SIZE === \count($rows));
                $actual = hash_init('sha256');
                $seen = 0;
                foreach ($query->pages($context->companyId, $context->accountId, $context, $request, $population) as $page) {
                    self::assertLessThanOrEqual(200, \count($page));
                    self::assertCount(1, $db->fetchFirstColumn("SELECT name FROM pg_cursors WHERE name LIKE 'net_evidence_%'"));
                    foreach ($page as $row) {
                        hash_update($actual, $row->cursor."\n");
                        ++$seen;
                    }
                }
                self::assertSame($count, $seen);
                self::assertSame(hash_final($expected), hash_final($actual), 'Same source rows/order/bytes as legacy pagination.');
                self::assertSame([], $db->fetchFirstColumn("SELECT name FROM pg_cursors WHERE name LIKE 'net_evidence_%'"));
                if ('gross' === $population) {
                    self::assertGreaterThan(200, $seen, 'Exercise more than one page.');
                }
            }
            $pages = $query->pages($context->companyId, $context->accountId, $context, $request, 'gross');
            $pages->rewind();
            self::assertCount(1, $db->fetchFirstColumn("SELECT name FROM pg_cursors WHERE name LIKE 'net_evidence_%'"));
            unset($pages);
            self::assertSame([], $db->fetchFirstColumn("SELECT name FROM pg_cursors WHERE name LIKE 'net_evidence_%'"));
            $db->rollBack();
        } finally {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }
            $f->cleanup();
            $db->close();
        }
    }

    public function testWideEvidencePageFailsBeforeTransferAndClosesCursor(): void
    {
        [$db, $f] = $this->fixture();
        try {
            $f->targets->returned('TRAIN-D-0', 'SKU', 1, $f->targets->readAt->modify('-1 day'), 'WIDE', reason: str_repeat('x', 600000));
            $db->beginTransaction();
            $db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $snapshot = $db->fetchOne('SELECT txid_current_snapshot()::text');
            self::assertIsString($snapshot);
            $context = new NetTrainingContext($f->protocol->companyId, $f->protocol->accountId, $f->targets->readAt, $f->targets->epoch, $snapshot);
            $request = new NetTargetRequest(['SKU'], new \DateTimeImmutable($f->protocol->from), new \DateTimeImmutable($f->protocol->to), new \DateTimeImmutable($f->protocol->from));
            try {
                foreach ((new NetForwardEvidenceQuery($db))->pages($context->companyId, $context->accountId, $context, $request, 'gross') as $page) {
                    self::assertLessThanOrEqual(200, \count($page));
                }
                self::fail('An oversized evidence page cannot be transferred or silently omitted.');
            } catch (\OverflowException $error) {
                self::assertSame('Evidence page byte budget exceeded.', $error->getMessage());
            }
            self::assertSame([], $db->fetchFirstColumn("SELECT name FROM pg_cursors WHERE name LIKE 'net_evidence_%'"));
            $db->rollBack();
        } finally {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }
            $f->cleanup();
            $db->close();
        }
    }

    /** @return array{Connection, NetQualityForwardFixture} */
    private function fixture(): array
    {
        self::bootKernel(['debug' => false]);
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $params = $connection->getParams();
        unset($params['wrapperClass']);
        $db = DriverManager::getConnection($params);
        $aggregate = self::getContainer()->get(AggregateNetForecastAction::class);
        self::assertInstanceOf(AggregateNetForecastAction::class, $aggregate);

        return [$db, new NetQualityForwardFixture($db, $aggregate)];
    }
}
