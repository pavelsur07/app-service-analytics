<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\NetForecast\NetTrainingReadException;
use App\Ingestion\Application\NetForecast\ReadNetTrainingAction;
use App\Ingestion\Infrastructure\Config\NetForecastCaptureEpochs;
use App\Ingestion\Infrastructure\Query\NetForecast\GrossTrainingAggregateQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingCursor;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingPopulation;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingRequest;
use App\Ingestion\Infrastructure\Query\NetForecast\ReturnTrainingAggregateQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\TrainingGroupDiagnosticsQuery;
use App\Tests\Support\Ingestion\NetTrainingFixture;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[SkipDatabaseRollback]
final class ReadNetTrainingActionTest extends KernelTestCase
{
    public function testMixedPostingCapacitiesRejectCancellationOverflowAndKeepCurrentTypeOnset(): void
    {
        $db = $this->db();
        $f = new NetTrainingFixture($db);
        try {
            for ($i = 0; $i < 31; ++$i) {
                $f->posting('STABLE-'.$i, 'OTHER', 1);
            }
            $day = $f->initial->modify('-1 day');
            $f->posting('D1', 'REQUESTED', 3, order: 'MIXED', businessDay: $day);
            $f->posting('D2', 'REQUESTED', 4, order: 'MIXED', businessDay: $day);
            $f->posting('C1', 'REQUESTED', 1, 'cancelled', order: 'MIXED', businessDay: $day);
            $f->posting('C2', 'REQUESTED', 2, 'cancelled', order: 'MIXED', businessDay: $day);
            $f->returned('MIXED', 'REQUESTED', 3, $f->terminal->modify('+2 hours'), 'Cancellation', 'Покупатель отменил заказ');
            $f->returned('MIXED', 'REQUESTED', 2, $f->terminal->modify('+5 days'), sourceKey: 'CLIENT');
            $f->returnCoverage();
            $read = fn () => ($this->action($db, $f, new MockClock($f->readAt)))($f->company->toRfc4122(), $f->account->toRfc4122(), new NetTrainingRequest(['REQUESTED']));
            $valid = $read();
            self::assertSame(41, $valid->gross[0]->sampleQuantity);
            self::assertSame(3, $valid->gross[0]->t1Quantity);
            self::assertSame(7, $valid->returns[1]->sampleQuantity);
            self::assertNotNull($valid->returns[1]->ageProfile);
            self::assertSame(2, $valid->returns[1]->ageProfile[60]->returnedQuantity);
            $f->returned('MIXED', 'REQUESTED', 4, $f->terminal->modify('+6 days'), 'Cancellation', 'Покупатель отменил заказ');
            $bad = $read();
            self::assertSame(31, $bad->gross[0]->sampleQuantity);
            self::assertSame(1, $bad->gross[0]->excludedDays);
            self::assertSame(0, $bad->returns[1]->sampleQuantity);
            self::assertSame(1, $bad->returns[0]->excludedGroups);
            $db->beginTransaction();
            $db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $snapshot = $db->fetchOne('SELECT txid_current_snapshot()::text');
            self::assertIsString($snapshot);
            $context = new NetTrainingContext($f->company->toRfc4122(), $f->account->toRfc4122(), $f->readAt, $f->epoch, $snapshot);
            foreach ([NetTrainingPopulation::Gross, NetTrainingPopulation::Returns] as $population) {
                $rows = array_map(TrainingGroupDiagnosticsQuery::mapRow(...), (new TrainingGroupDiagnosticsQuery($db))->build($context->companyId, $context->accountId, $context, new NetTrainingRequest(['REQUESTED']), $population)->executeQuery()->fetchAllAssociative());
                $mixed = array_values(array_filter($rows, static fn ($row): bool => 'MIXED' === $row->orderKey));
                self::assertCount(1, $mixed);
                self::assertSame('contradictory_quantity', $mixed[0]->exclusionReason);
            }
            $db->rollBack();
            $f->returned('MIXED', 'REQUESTED', 3, $f->terminal->modify('+10 days'));
            $corrected = $read();
            self::assertSame(7, $corrected->returns[1]->sampleQuantity);
            self::assertNotNull($corrected->returns[1]->ageProfile);
            self::assertSame(5, $corrected->returns[1]->ageProfile[60]->returnedQuantity);
        } finally {
            $this->cleanup($db, $f);
        }
    }

    public function testOneReturnGroupAcrossSourceDaysHasOneDiagnosticCursorPosition(): void
    {
        $db = $this->db();
        $f = new NetTrainingFixture($db);
        try {
            $f->posting('P1', 'REQUESTED', 10, order: 'ORDER');
            $f->posting('P2', 'REQUESTED', 20, order: 'ORDER', businessDay: $f->initial->modify('-1 day'));
            $f->returnCoverage();
            $db->beginTransaction();
            $db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $snapshot = $db->fetchOne('SELECT txid_current_snapshot()::text');
            self::assertIsString($snapshot);
            $context = new NetTrainingContext($f->company->toRfc4122(), $f->account->toRfc4122(), $f->readAt, $f->epoch, $snapshot);
            $rows = array_map(TrainingGroupDiagnosticsQuery::mapRow(...), (new TrainingGroupDiagnosticsQuery($db))->build($context->companyId, $context->accountId, $context, new NetTrainingRequest(['REQUESTED']), NetTrainingPopulation::Returns, 1)->executeQuery()->fetchAllAssociative());
            self::assertCount(1, $rows);
            self::assertSame('ORDER', $rows[0]->orderKey);
            self::assertTrue($rows[0]->eligible);
        } finally {
            $this->cleanup($db, $f);
        }
    }

    public function testReturnDiagnosticsDistinguishMixedOriginsFromUnobservedDelivery(): void
    {
        $db = $this->db();
        $f = new NetTrainingFixture($db);
        try {
            $f->posting('P1', 'REQUESTED', 10, order: 'MIXED');
            $f->posting('P2', 'REQUESTED', 20, order: 'MIXED', terminalAt: $f->terminal->modify('+1 day'));
            $f->posting('TERMINAL', 'REQUESTED', 30, terminalOnly: true);
            $f->posting('UNKNOWN', 'REQUESTED', 40);
            $f->returned('UNKNOWN', 'REQUESTED', 2, $f->terminal->modify('+5 days'), 'UnrecognizedReturnType');
            $f->returned('UNKNOWN', 'REQUESTED', 2, $f->terminal->modify('+15 days'));
            $f->returnCoverage();
            $db->beginTransaction();
            $db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $snapshot = $db->fetchOne('SELECT txid_current_snapshot()::text');
            self::assertIsString($snapshot);
            $context = new NetTrainingContext($f->company->toRfc4122(), $f->account->toRfc4122(), $f->readAt, $f->epoch, $snapshot);
            $rows = array_map(TrainingGroupDiagnosticsQuery::mapRow(...), (new TrainingGroupDiagnosticsQuery($db))->build($context->companyId, $context->accountId, $context, new NetTrainingRequest(['REQUESTED']), NetTrainingPopulation::Returns)->executeQuery()->fetchAllAssociative());
            self::assertCount(3, $rows);
            self::assertSame('ambiguous_delivery_allocation', $rows[0]->exclusionReason);
            self::assertSame('delivery_observation_unknown', $rows[1]->exclusionReason);
            self::assertSame('unknown_return_type', $rows[2]->exclusionReason);
            self::assertFalse($rows[0]->eligible);
            self::assertFalse($rows[1]->eligible);
        } finally {
            $this->cleanup($db, $f);
        }
    }

    public function testStrongOuterOwnerKeepsItsSnapshotAndLocalSettings(): void
    {
        $db = $this->db();
        $f = new NetTrainingFixture($db);
        try {
            $f->posting('ORDER', 'REQUESTED', 10);
            $f->returnCoverage();
            $db->beginTransaction();
            $db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $db->executeStatement("SET LOCAL statement_timeout = '23s'");
            $db->executeStatement('SET LOCAL jit = on');
            $snapshot = $db->fetchOne('SELECT txid_current_snapshot()::text');
            $batch = ($this->action($db, $f, new MockClock($f->readAt)))($f->company->toRfc4122(), $f->account->toRfc4122(), new NetTrainingRequest(['REQUESTED']));
            self::assertTrue($db->isTransactionActive());
            self::assertSame('repeatable read', $db->fetchOne('SHOW transaction_isolation'));
            self::assertSame('on', $db->fetchOne('SHOW transaction_read_only'));
            self::assertSame('23s', $db->fetchOne('SHOW statement_timeout'));
            self::assertSame('on', $db->fetchOne('SHOW jit'));
            self::assertSame($snapshot, $db->fetchOne('SELECT txid_current_snapshot()::text'));
            self::assertSame(10, $batch->returns[0]->sampleQuantity);
        } finally {
            $this->cleanup($db, $f);
        }
    }

    public function testDiagnosticsKeysetIsBoundToScopeAndCannotOutliveTheActualSnapshot(): void
    {
        $db = $this->db();
        $f = new NetTrainingFixture($db);
        try {
            $f->posting('ORDER-A', 'REQUESTED', 1);
            $f->posting('ORDER-B', 'REQUESTED', 1);
            $db->beginTransaction();
            $db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $snapshot = $db->fetchOne('SELECT txid_current_snapshot()::text');
            self::assertIsString($snapshot);
            $context = new NetTrainingContext($f->company->toRfc4122(), $f->account->toRfc4122(), $f->readAt, $f->epoch, $snapshot);
            $request = new NetTrainingRequest(['REQUESTED']);
            $query = new TrainingGroupDiagnosticsQuery($db);
            $first = array_map(TrainingGroupDiagnosticsQuery::mapRow(...), $query->build($context->companyId, $context->accountId, $context, $request, NetTrainingPopulation::Gross, 1)->executeQuery()->fetchAllAssociative());
            self::assertCount(2, $first);
            self::assertSame('ORDER-A', $first[0]->orderKey);
            $cursor = NetTrainingCursor::fromString(NetTrainingCursor::afterRow($context, $request, NetTrainingPopulation::Gross, $first[0])->toString());
            $next = array_map(TrainingGroupDiagnosticsQuery::mapRow(...), $query->build($context->companyId, $context->accountId, $context, $request, NetTrainingPopulation::Gross, 1, $cursor)->executeQuery()->fetchAllAssociative());
            self::assertCount(1, $next);
            self::assertSame('ORDER-B', $next[0]->orderKey);
            $foreign = new NetTrainingContext(Uuid::v7()->toRfc4122(), $context->accountId, $context->readAt, $f->epoch, $snapshot);
            try {
                $query->build($foreign->companyId, $foreign->accountId, $foreign, $request, NetTrainingPopulation::Gross, 1, $cursor);
                self::fail('Cursor from another company must be rejected.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
            $db->rollBack();
            $f->posting('ORDER-C', 'REQUESTED', 1);
            $db->beginTransaction();
            $db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $stale = array_map(TrainingGroupDiagnosticsQuery::mapRow(...), $query->build($context->companyId, $context->accountId, $context, $request, NetTrainingPopulation::Gross, 1, $cursor)->executeQuery()->fetchAllAssociative());
            self::assertCount(1, $stale);
            self::assertFalse($stale[0]->inputComplete);
            self::assertNull($stale[0]->orderKey);
            self::assertSame('diagnostic_snapshot_unavailable', $stale[0]->exclusionReason);
        } finally {
            $this->cleanup($db, $f);
        }
    }

    public function testLateCommitWithOldCaptureTimeCannotEnterEitherPopulationAfterSnapshotPin(): void
    {
        $db = $this->db();
        $other = DriverManager::getConnection($db->getParams());
        $fixture = new NetTrainingFixture($db);
        try {
            for ($i = 0; $i < 31; ++$i) {
                $fixture->posting('VISIBLE-'.$i, 'REQUESTED', 1);
            }
            $fixture->returnCoverage();
            $other->beginTransaction();
            $late = new NetTrainingFixture($other, $fixture->company, $fixture->account);
            $late->posting('LATE', 'OTHER', 100);
            $properties = [];
            $clock = new class($fixture->readAt, static function () use ($db, $other, &$properties): void {
                $properties = [$db->fetchOne('SHOW transaction_isolation'), $db->fetchOne('SHOW transaction_read_only')];
                $other->commit();
            }) implements ClockInterface {
                public function __construct(private readonly \DateTimeImmutable $at, private readonly \Closure $beforeRead)
                {
                }

                public function now(): \DateTimeImmutable
                {
                    ($this->beforeRead)();

                    return $this->at;
                }
            };
            $batch = ($this->action($db, $fixture, $clock))($fixture->company->toRfc4122(), $fixture->account->toRfc4122(), new NetTrainingRequest(['REQUESTED']));
            self::assertSame(['repeatable read', 'on'], $properties);
            self::assertNull($batch->unavailableReason);
            self::assertSame(31, $batch->gross[0]->sampleQuantity);
            self::assertSame(31, $batch->returns[0]->sampleQuantity);
            self::assertSame(131, $db->fetchOne('SELECT SUM(quantity)::bigint FROM sales_fact WHERE company_id = ? AND marketplace_account_id = ?', [$fixture->company->toRfc4122(), $fixture->account->toRfc4122()]));
            self::assertFalse($db->isTransactionActive());
            self::assertSame('off', $db->fetchOne('SHOW transaction_read_only'));
        } finally {
            if ($other->isTransactionActive()) {
                $other->rollBack();
            }
            $this->cleanup($db, $fixture);
            $other->close();
        }
    }

    public function testMissingEpochCannotStartModelReadsOrInventASourceHistory(): void
    {
        $db = $this->db();
        $fixture = new NetTrainingFixture($db);
        $action = new ReadNetTrainingAction($db, new GrossTrainingAggregateQuery($db), new ReturnTrainingAggregateQuery($db), new NetForecastCaptureEpochs(), new MockClock($fixture->readAt));
        $batch = $action($fixture->company->toRfc4122(), $fixture->account->toRfc4122(), new NetTrainingRequest(['REQUESTED']));
        self::assertSame('source_history_unavailable', $batch->unavailableReason);
        self::assertNull($batch->context);
        self::assertSame([], $batch->gross);
        self::assertSame([], $batch->returns);
        self::assertFalse($db->isTransactionActive());
    }

    public function testReadCommittedOwnerIsRejectedWithoutChangingItsTransaction(): void
    {
        $db = $this->db();
        $fixture = new NetTrainingFixture($db);
        $db->beginTransaction();
        try {
            try {
                ($this->action($db, $fixture, new MockClock($fixture->readAt)))($fixture->company->toRfc4122(), $fixture->account->toRfc4122(), new NetTrainingRequest(['REQUESTED']));
                self::fail('A weak outer transaction cannot provide one model snapshot.');
            } catch (NetTrainingReadException $error) {
                self::assertSame('training_snapshot_unsupported', $error->reason);
            }
            self::assertTrue($db->isTransactionActive());
            self::assertSame('read committed', $db->fetchOne('SHOW transaction_isolation'));
        } finally {
            $db->rollBack();
        }
    }

    private function db(): Connection
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $database = $db->fetchOne('SELECT current_database()');
        self::assertIsString($database);
        self::assertMatchesRegularExpression('/^app_test(?:[1-9][0-9]*)?$/D', $database);

        return $db;
    }

    private function action(Connection $db, NetTrainingFixture $fixture, ClockInterface $clock): ReadNetTrainingAction
    {
        $e = $fixture->epoch;
        $epochs = new NetForecastCaptureEpochs(json_encode([['epochId' => $e->id, 'captureVersion' => $e->captureVersion, 'startUtc' => $e->start->format('Y-m-d\TH:i:s\Z'), 'endUtc' => null, 'cutoverRelease' => $e->cutoverRelease]], \JSON_THROW_ON_ERROR));

        return new ReadNetTrainingAction($db, new GrossTrainingAggregateQuery($db), new ReturnTrainingAggregateQuery($db), $epochs, $clock);
    }

    private function cleanup(Connection $db, NetTrainingFixture $fixture): void
    {
        if ($db->isTransactionActive()) {
            $db->rollBack();
        }
        foreach (['sales_fact_revision', 'marketplace_return_fact_revision', 'marketplace_day_load_receipt', 'marketplace_posting_status', 'marketplace_day_load', 'marketplace_return_fact', 'sales_fact'] as $table) {
            $db->executeStatement('DELETE FROM '.$table.' WHERE company_id = ? AND marketplace_account_id = ?', [$fixture->company->toRfc4122(), $fixture->account->toRfc4122()]);
        }
    }
}
