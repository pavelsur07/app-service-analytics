<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\NetForecast\AggregateNetForecastAction;
use App\Ingestion\Application\NetForecast\ApplyOpenRetentionAction;
use App\Ingestion\Application\NetForecast\AssessDeliveredRiskAction;
use App\Ingestion\Application\NetForecast\AssessOpenRetentionAction;
use App\Ingestion\Application\NetForecast\BuildPostHandoverGrossRateAction;
use App\Ingestion\Application\NetForecast\BuildPreHandoverGrossRateAction;
use App\Ingestion\Application\NetForecast\BuildReturnCurveAction;
use App\Ingestion\Application\NetForecast\NetForecastBatch;
use App\Ingestion\Application\NetForecast\NetForecastPart;
use App\Ingestion\Application\NetForecast\NetTrainingReadException;
use App\Ingestion\Application\NetForecast\ReadNetForecastAction;
use App\Ingestion\Application\NetForecast\ReadNetTrainingAction;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Infrastructure\Config\NetForecastCaptureEpochs;
use App\Ingestion\Infrastructure\Query\NetForecast\GrossTrainingAggregateQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetLimits;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetRequest;
use App\Ingestion\Infrastructure\Query\NetForecast\ReturnTrainingAggregateQuery;
use App\Tests\Support\Ingestion\NetTargetFixture;
use App\Tests\Support\Ingestion\NetTrainingFixture;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

#[SkipDatabaseRollback]
final class ReadNetForecastActionTest extends KernelTestCase
{
    /** @var list<NetTargetFixture> */
    private array $fixtures = [];
    /** @var list<Connection> */
    private array $connections = [];

    protected function setUp(): void
    {
        self::assertTrue(class_exists(ReadNetForecastAction::class), 'The current read entrypoint must exist.');
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
        foreach ($this->fixtures as $fixture) {
            $fixture->cleanup();
        }
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        parent::tearDown();
    }

    public function testEmptyRequestTouchesNeitherDatabaseNorClock(): void
    {
        [$db, $f] = $this->fixture();
        $clock = $this->clock($f);
        $db->resetObservations();
        $result = ($this->action($db, $f, $clock))($f->company->toRfc4122(), $f->account->toRfc4122(), $this->request($f, []));
        self::assertSame([], $db->sql);
        self::assertSame(0, $clock->calls);
        self::assertSame([], $result->skus);
        self::assertNull($result->context);
        self::assertNull($result->readAt);
        self::assertNull($result->unavailableReason);
        self::assertSame('0', $result->quantities?->expectedNetNumerator);
        self::assertSame('1', $result->quantities->expectedNetDenominator);
        foreach (['company', 'account'] as $invalid) {
            try {
                ($this->action($db, $f, $clock))('company' === $invalid ? 'invalid' : $f->company->toRfc4122(), 'account' === $invalid ? 'invalid' : $f->account->toRfc4122(), $this->request($f, []));
                self::fail('Invalid scope must be rejected even for an empty request.');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $db->sql);
                self::assertSame(0, $clock->calls);
            }
        }
    }

    public function testAccumulationCannotSilentlyExtendPast184CalendarDays(): void
    {
        [$db, $f] = $this->fixture();
        $day = $this->request($f)->from;
        $clock = $this->clock($f);
        $db->resetObservations();
        foreach ([[$day, $day->modify('+184 days'), null], [$day, $day, $day->modify('-184 days')]] as [$from, $to, $accumulation]) {
            try {
                ($this->action($db, $f, $clock))($f->company->toRfc4122(), $f->account->toRfc4122(), new NetTargetRequest(['SKU'], $from, $to, $accumulation));
                self::fail('185 calendar dates must be rejected before reading.');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $db->sql);
                self::assertSame(0, $clock->calls);
            }
        }
        $valid = new NetTargetRequest([], $day, $day, $day->modify('-183 days'));
        self::assertSame('0', ($this->action($db, $f, $clock))($f->company->toRfc4122(), $f->account->toRfc4122(), $valid)->quantities?->expectedNetNumerator);
        self::assertSame([], $db->sql);
        $this->terminalZero($f);
        $previous = $day->modify('-1 day');
        $f->receipt(MarketplaceReportType::OzonPostingFboList, $previous, $previous, $f->readAt->modify('-1 hour'));
        $withAccumulation = ($this->action($db, $f, $clock))($f->company->toRfc4122(), $f->account->toRfc4122(), new NetTargetRequest(['SKU'], $day, $day, $previous));
        self::assertNull($withAccumulation->unavailableReason);
        self::assertSame('0', $withAccumulation->quantities?->expectedNetNumerator);
        self::assertSame([$previous->format('Y-m-d'), $day->format('Y-m-d')], array_column($withAccumulation->skus[0]->days, 'businessDate'));
    }

    public function testEntryComputeBudgetIncludesAggregateWithDefaultIndependentLimits(): void
    {
        [$db, $f] = $this->fixture();
        $this->terminalZero($f);
        $db->resetObservations();
        $result = ($this->action($db, $f, $this->clock($f), limits: new NetTargetLimits(computeSeconds: 0.000000001)))($f->company->toRfc4122(), $f->account->toRfc4122(), $this->request($f));
        self::assertSame(3, $db->populations);
        self::assertSame('input_budget_exceeded', $result->unavailableReason);
        self::assertNull($result->quantities);
        self::assertSame([], $result->skus);
    }

    public function testOnePinnedSnapshotPrecedesOnlyClockAndSeesOneCohort(): void
    {
        [$db, $f] = $this->fixture(training: true);
        $this->freshTargets($f);
        $other = $this->other($db);
        $writer = new NetTargetFixture($other, $f->readAt, $f->company, $f->account);
        $other->beginTransaction();
        // The accepted return changes both the mature training curve and current D.
        $writer->returned('TRAIN-D-0', 'SKU', 6, $f->readAt->modify('-50 days'), 'LATE-TRAIN');
        $writer->returned('D', 'SKU', 7, $f->readAt->modify('-1 hour'), 'LATE-CURRENT');
        $writer->currentReturns($f->readAt->modify('-1 microsecond'));
        $pinned = null;
        $clock = $this->clock($f, static function () use ($db, $other, &$pinned): void {
            self::assertSame('repeatable read', $db->fetchOne('SHOW transaction_isolation'));
            self::assertSame('on', $db->fetchOne('SHOW transaction_read_only'));
            $pinned = $db->fetchOne('SELECT txid_current_snapshot()::text');
            $other->commit();
        });
        $db->resetObservations();
        $old = ($this->action($db, $f, $clock))($f->company->toRfc4122(), $f->account->toRfc4122(), $this->request($f));
        self::assertSame(1, $clock->calls);
        self::assertSame(3, $db->populations);
        self::assertSame(1, $db->transactionStarts);
        self::assertSame([$pinned, $pinned, $pinned], $db->populationSnapshots);
        self::assertSame(array_fill(0, 3, $f->readAt->format('Y-m-d H:i:s.u')), $db->populationReadAts);
        self::assertNotNull($old->context);
        self::assertSame($pinned, $old->context->snapshotId);
        self::assertSame($f->readAt->format('U.u'), $old->readAt?->format('U.u'));
        self::assertSame($old->readAt, $old->context->readAt);
        self::assertSame($f->epoch->start->format('U.u'), $old->context->epoch->start->format('U.u'));
        self::assertSame('90', $old->quantities?->expectedNetNumerator);
        self::assertSame('70', $old->quantities->currentD);
        self::assertFalse($db->isTransactionActive());
        $db->resetObservations();
        $fresh = $this->read($db, $f);
        self::assertSame(3, $db->populations);
        self::assertSame('63', $fresh->quantities?->currentD);
        self::assertSame('477', $fresh->quantities->futureReturnsNumerator);
        self::assertSame('100', $fresh->quantities->futureReturnsDenominator);
        self::assertSame('108049', $fresh->quantities->expectedNetNumerator);
        self::assertSame('1300', $fresh->quantities->expectedNetDenominator);
        self::assertNotSame($old->context->snapshotId, $fresh->context?->snapshotId);
    }

    public function testConcurrentCommitBetweenTrainingAndTargetCannotMixInputs(): void
    {
        [$db, $f] = $this->fixture(training: true);
        $this->freshTargets($f);
        $other = $this->other($db);
        $writer = new NetTargetFixture($other, $f->readAt, $f->company, $f->account);
        $other->beginTransaction();
        $writer->returned('D', 'SKU', 7, $f->readAt->modify('-1 hour'), 'CONCURRENT-RETURN');
        $writer->currentReturns($f->readAt, false);
        $db->afterReturns = static function () use ($other): void { $other->commit(); };
        $db->resetObservations();
        $old = $this->read($db, $f);
        self::assertSame(3, $db->populations);
        self::assertNull($old->unavailableReason);
        self::assertSame('90', $old->quantities?->expectedNetNumerator);
        $new = $this->read($db, $f);
        self::assertSame('returns_coverage_missing', $new->unavailableReason);
        self::assertNull($new->quantities);
        self::assertSame(7, $new->skus[0]->days[0]->groups[0]->returns[0]->quantity);
        // A later real completed observation repairs the failure; known R remains deducted once.
        $writer->currentReturns($f->readAt->modify('+1 second'));
        $later = new NetTargetFixture($db, $f->readAt->modify('+2 seconds'), $f->company, $f->account);
        $repaired = $this->read($db, $later);
        self::assertNull($repaired->unavailableReason);
        self::assertSame('63', $repaired->quantities?->currentD);
        self::assertSame('167', $repaired->quantities->expectedNetNumerator);
        self::assertSame('2', $repaired->quantities->expectedNetDenominator);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPopulationSelectCountDoesNotGrowWithSkuOrGroupCount(): void
    {
        [$db, $f] = $this->fixture();
        $f->sale('OPEN-0', 'SKU', 1, 'awaiting_packaging', $f->readAt->modify('-2 hours'));
        $f->currentReturns();
        foreach ([1, 200] as $groups) {
            if (200 === $groups) {
                for ($i = 1; $i < 200; ++$i) {
                    $f->sale('OPEN-'.$i, 'SKU', 1, 'awaiting_packaging', $f->readAt->modify('-2 hours'));
                }
            }
            foreach ([['SKU'], array_merge(['SKU'], array_map(static fn (int $i): string => 'EMPTY-'.$i, range(1, 49)))] as $skus) {
                $clock = $this->clock($f);
                $db->resetObservations();
                $result = ($this->action($db, $f, $clock))($f->company->toRfc4122(), $f->account->toRfc4122(), $this->request($f, $skus));
                self::assertSame(3, $db->populations);
                self::assertSame(1, $clock->calls);
                self::assertCount(\count($skus), $result->skus, 'groups='.$groups.' skus='.\count($skus).' reason='.$result->unavailableReason.' heap='.memory_get_usage(true).' peak='.memory_get_peak_usage(true));
                $last = array_key_last($result->skus);
                self::assertNotNull($last);
                self::assertCount($groups, $result->skus[$last]->days[0]->groups);
            }
        }
    }

    public function testTargetTransferBudgetIsReducedBeforeJsonAndRefusesWholeResult(): void
    {
        [$db, $f] = $this->fixture();
        $this->terminalZero($f);
        foreach ([new NetTargetLimits(outputBytes: 1), new NetTargetLimits(phpAllocationBytes: 1), new NetTargetLimits(phpPeakBytes: 1)] as $limits) {
            $db->resetObservations();
            $result = ($this->action($db, $f, $this->clock($f), limits: $limits))($f->company->toRfc4122(), $f->account->toRfc4122(), $this->request($f));
            self::assertSame('input_budget_exceeded', $result->unavailableReason);
            self::assertNull($result->quantities);
            self::assertSame([], $result->skus);
            self::assertSame(1 === $limits->phpPeakBytes ? 2 : 3, $db->populations);
        }
    }

    public function testSupportedOuterSnapshotRestoresSettingsAndTimeoutRollback(): void
    {
        [$db, $f] = $this->fixture();
        $this->terminalZero($f);
        foreach (['REPEATABLE READ', 'SERIALIZABLE'] as $isolation) {
            $db->beginTransaction();
            $db->executeStatement('SET TRANSACTION ISOLATION LEVEL '.$isolation.', READ ONLY');
            $db->executeStatement("SET LOCAL statement_timeout = '23s'");
            $db->executeStatement('SET LOCAL jit = on');
            $snapshot = $db->fetchOne('SELECT txid_current_snapshot()::text');
            $db->createSavepoint('caller_owned');
            foreach ([null, 'gross', 'returns', 'target'] as $timeout) {
                $db->timeoutPopulation = $timeout;
                $clock = $this->clock($f);
                $result = ($this->action($db, $f, $clock))($f->company->toRfc4122(), $f->account->toRfc4122(), $this->request($f));
                self::assertSame(1, $clock->calls);
                self::assertSame(null === $timeout ? null : 'query_budget_exceeded', $result->unavailableReason);
                self::assertSame(null === $timeout ? '0' : null, $result->quantities?->expectedNetNumerator);
                self::assertSame(1, $db->fetchOne('SELECT 1'), 'Caller remains usable after real SQLSTATE 57014.');
                self::assertSame(strtolower($isolation), $db->fetchOne('SHOW transaction_isolation'));
                self::assertSame('on', $db->fetchOne('SHOW transaction_read_only'));
                self::assertSame('23s', $db->fetchOne('SHOW statement_timeout'));
                self::assertSame('on', $db->fetchOne('SHOW jit'));
                self::assertSame($snapshot, $db->fetchOne('SELECT txid_current_snapshot()::text'));
            }
            $db->rollbackSavepoint('caller_owned');
            $db->releaseSavepoint('caller_owned');
            $db->rollBack();
        }
        self::assertSame('off', $db->fetchOne('SHOW transaction_read_only'));
    }

    public function testUnsupportedOuterTransactionIsRejectedBeforeClockOrPopulationReads(): void
    {
        [$db, $f] = $this->fixture();
        foreach (['READ COMMITTED, READ ONLY', 'REPEATABLE READ, READ WRITE', 'SERIALIZABLE, READ WRITE'] as $settings) {
            $db->beginTransaction();
            $db->executeStatement('SET TRANSACTION ISOLATION LEVEL '.$settings);
            $clock = $this->clock($f);
            $db->resetObservations();
            try {
                ($this->action($db, $f, $clock))($f->company->toRfc4122(), $f->account->toRfc4122(), $this->request($f));
                self::fail('Unsupported owner cannot provide the shared model snapshot.');
            } catch (NetTrainingReadException $error) {
                self::assertSame('training_snapshot_unsupported', $error->reason);
                self::assertSame(0, $clock->calls);
                self::assertSame(0, $db->populations);
                self::assertSame([], array_values(array_filter($db->sql, static fn (string $sql): bool => !str_starts_with($sql, 'SHOW '))));
                self::assertSame(1, $db->fetchOne('SELECT 1'));
            } finally {
                $db->rollBack();
            }
        }
    }

    public function testRealCurrentProducerToExactNetAndZero(): void
    {
        [$db, $f] = $this->fixture(training: true);
        $this->freshTargets($f);
        $result = $this->read($db, $f);
        self::assertNull($result->unavailableReason);
        self::assertSame('70', $result->quantities?->currentD);
        self::assertSame('5', $result->quantities->futureReturnsNumerator);
        self::assertSame('1', $result->quantities->futureReturnsDenominator);
        self::assertSame('25', $result->quantities->retainedOpenNumerator);
        self::assertSame('90', $result->quantities->expectedNetNumerator);
        self::assertSame('1', $result->quantities->expectedNetDenominator);
        $parts = $this->parts($result);
        self::assertSame('12345', $parts['D']->trace->amountMinor);
        self::assertSame('RUB', $parts['D']->trace->currency);
        self::assertSame('D', $parts['D']->sourceRowId);
        self::assertSame(2, $parts['D']->trace->latestFactRevision);
        self::assertSame('OPEN', $parts['OPEN']->sourceRowId);
        [$zeroDb, $zero] = $this->fixture();
        $this->terminalZero($zero);
        $batch = $this->read($zeroDb, $zero);
        self::assertNull($batch->unavailableReason);
        self::assertSame('0', $batch->quantities?->expectedNetNumerator);
        self::assertNotNull($batch->context);
        $zero->currentReturns($zero->readAt, false);
        self::assertNull($this->read($zeroDb, $zero)->quantities);
    }

    public function testAllPreHandoverCancellationsGiveExactZeroWithoutTraining(): void
    {
        [$db, $f] = $this->fixture();
        foreach (['A' => 7, 'B' => 13] as $source => $quantity) {
            $f->sale($source, 'SKU', $quantity, 'awaiting_packaging', $f->readAt->modify('-3 hours'), 'ORDER');
            $f->sale($source, 'SKU', $quantity, 'cancelled', $f->readAt->modify('-2 hours'), 'ORDER');
        }
        $f->returned('ORDER', 'SKU', 20, $f->readAt->modify('-1 hour'), 'T1', 'Cancellation', 'Покупатель отменил заказ');
        $f->currentReturns();
        $batch = $this->read($db, $f);
        self::assertNull($batch->unavailableReason);
        $group = $batch->skus[0]->days[0]->groups[0];
        self::assertCount(2, $group->parts);
        self::assertCount(1, $group->returns);
        self::assertSame('Cancellation', $group->returns[0]->returnType);
        foreach ([$batch, $batch->skus[0], $batch->skus[0]->days[0], $group, ...$group->parts] as $node) {
            self::assertSame('0', $node->quantities?->currentD);
            self::assertSame('0', $node->quantities->eligibleOpenQuantity);
            self::assertSame('0', $node->quantities->expectedNetNumerator);
            self::assertSame('1', $node->quantities->expectedNetDenominator);
        }
        $f->currentReturns($f->readAt, false);
        $failed = $this->read($db, $f);
        self::assertSame('returns_coverage_missing', $failed->unavailableReason);
        self::assertNull($failed->quantities);
    }

    public function testPartialReturnAcrossSameOriginPartsIsDeductedExactlyOnce(): void
    {
        [$db, $f] = $this->fixture(training: true);
        $origin = $f->readAt->modify('-1 day');
        $f->delivered('A', 'SKU', 7, $origin, 'ORDER');
        $f->delivered('B', 'SKU', 13, $origin, 'ORDER');
        $f->returned('ORDER', 'SKU', 6, $f->readAt->modify('-1 hour'), 'PARTIAL-R');
        $f->daily($origin);
        $f->currentReturns();
        $batch = $this->read($db, $f);
        self::assertNull($batch->unavailableReason);
        $group = $batch->skus[0]->days[0]->groups[0];
        self::assertCount(1, $group->returns);
        self::assertSame('PARTIAL-R', $group->returns[0]->sourceRowId);
        self::assertSame(6, $group->returns[0]->quantity);
        foreach ([$batch, $batch->skus[0], $batch->skus[0]->days[0], $group] as $node) {
            self::assertSame('14', $node->quantities?->currentD);
            self::assertSame('1', $node->quantities->futureReturnsNumerator);
            self::assertSame('1', $node->quantities->futureReturnsDenominator);
            self::assertSame('0', $node->quantities->eligibleOpenQuantity);
            self::assertSame('13', $node->quantities->expectedNetNumerator);
            self::assertSame('1', $node->quantities->expectedNetDenominator);
        }
        $parts = $this->parts($batch);
        self::assertSame(['A', 'B'], array_keys($parts));
        foreach (['A' => ['1', '1', '13'], 'B' => ['13', '13', '169']] as $source => [$d, $future, $net]) {
            $part = $parts[$source];
            self::assertSame($d, $part->quantities?->currentD);
            self::assertSame($future, $part->quantities->futureReturnsNumerator);
            self::assertSame('14', $part->quantities->futureReturnsDenominator);
            self::assertSame($net, $part->quantities->expectedNetNumerator);
            self::assertSame('14', $part->quantities->expectedNetDenominator);
            self::assertSame('12345', $part->trace->amountMinor);
            self::assertSame('RUB', $part->trace->currency);
            self::assertSame($source, $part->trace->sourceRowId);
            self::assertSame(2, $part->trace->latestFactRevision);
            self::assertNotNull($part->trace->firstRevisionId);
            self::assertNotNull($part->trace->latestRevisionId);
        }
    }

    public function testEarlierDayConsumesReturnBeforeCurrentForecastAndSiblingSkuKeepsItsDelivery(): void
    {
        [$db, $f] = $this->fixture(training: true);
        $origin = $f->readAt->modify('-1 day');
        $f->delivered('EARLY', 'SKU', 3, $origin, 'ORDER', $this->request($f)->from->modify('-1 day'));
        $f->delivered('CURRENT', 'SKU', 4, $origin, 'ORDER');
        $f->delivered('SIBLING', 'OTHER', 1, $origin, 'ORDER');
        $f->returned('ORDER', 'SKU', 5, $f->readAt->modify('-1 hour'), 'CROSS-DAY-R');
        $f->daily($origin);
        $f->currentReturns();
        $batch = $this->read($db, $f);
        self::assertNull($batch->unavailableReason);
        self::assertSame(['CURRENT'], array_keys($this->parts($batch)));
        $group = $batch->skus[0]->days[0]->groups[0];
        self::assertCount(1, $group->returns);
        self::assertSame('CROSS-DAY-R', $group->returns[0]->sourceRowId);
        self::assertSame(5, $group->returns[0]->quantity);
        foreach ([$batch, $batch->skus[0], $batch->skus[0]->days[0], $group, $group->parts[0]] as $node) {
            self::assertSame('2', $node->quantities?->currentD);
            self::assertSame('1', $node->quantities->futureReturnsNumerator);
            self::assertSame('7', $node->quantities->futureReturnsDenominator);
            self::assertSame('13', $node->quantities->expectedNetNumerator);
            self::assertSame('7', $node->quantities->expectedNetDenominator);
        }
        $sibling = ($this->action($db, $f, $this->clock($f)))($f->company->toRfc4122(), $f->account->toRfc4122(), $this->request($f, ['OTHER']));
        self::assertNull($sibling->unavailableReason);
        self::assertSame(['SIBLING'], array_keys($this->parts($sibling)));
        self::assertSame([], $sibling->skus[0]->days[0]->groups[0]->returns);
        self::assertSame('1', $sibling->quantities?->currentD);
        self::assertSame('13', $sibling->quantities->expectedNetNumerator);
        self::assertSame('14', $sibling->quantities->expectedNetDenominator);
    }

    public function testUnknownPartKeepsFullAncestorsUnavailableBesideKnownDelivery(): void
    {
        [$db, $f] = $this->fixture(training: true);
        $origin = $f->readAt->modify('-1 day');
        $f->delivered('KNOWN', 'SKU', 14, $origin);
        $f->sale('UNKNOWN', 'SKU', 3, 'awaiting_packaging', $f->readAt->modify('-2 hours'), substatus: 'unsupported_substatus');
        $f->daily($origin);
        $f->currentReturns();
        $batch = $this->read($db, $f);
        $parts = $this->parts($batch);
        self::assertSame(['KNOWN', 'UNKNOWN'], array_keys($parts));
        self::assertNull($parts['KNOWN']->unavailableReason);
        self::assertSame('13', $parts['KNOWN']->quantities?->expectedNetNumerator);
        self::assertSame('1', $parts['KNOWN']->quantities->expectedNetDenominator);
        $groups = array_column($batch->skus[0]->days[0]->groups, null, 'orderNumber');
        self::assertSame('13', $groups['KNOWN']->quantities?->expectedNetNumerator);
        foreach ([$batch, $batch->skus[0], $batch->skus[0]->days[0], $groups['UNKNOWN'], $parts['UNKNOWN']] as $node) {
            self::assertSame('unknown_outcome', $node->unavailableReason);
            self::assertNull($node->quantities, 'Full total cannot expose the known subset or substitute zero for unknown.');
        }
    }

    public function testDifferentOriginsWithPositiveResidualStayUnavailableThroughReader(): void
    {
        [$db, $f] = $this->fixture(training: true);
        $origin = $f->readAt->modify('-2 days');
        $f->delivered('A', 'SKU', 7, $origin, 'ORDER');
        $f->delivered('B', 'SKU', 13, $origin->modify('+1 day'), 'ORDER');
        $f->returned('ORDER', 'SKU', 7, $f->readAt->modify('-1 hour'));
        $f->daily($origin);
        $f->currentReturns();
        $batch = $this->read($db, $f);
        $group = $batch->skus[0]->days[0]->groups[0];
        foreach ([$batch, $batch->skus[0], $batch->skus[0]->days[0], $group, ...$group->parts] as $node) {
            self::assertSame('ambiguous_delivery_allocation', $node->unavailableReason);
            self::assertNull($node->quantities);
        }
    }

    public function testFullyReturnedDifferentOriginsGiveExactZeroWithoutTraining(): void
    {
        [$db, $f] = $this->fixture();
        $f->delivered('A', 'SKU', 7, $f->readAt->modify('-2 days'), 'ORDER');
        $f->delivered('B', 'SKU', 13, $f->readAt->modify('-1 day'), 'ORDER');
        $f->returned('ORDER', 'SKU', 20, $f->readAt->modify('-1 hour'), 'ALL-R');
        $f->currentReturns();
        // Terminal zero needs current source closure, but no age, daily tail or model.
        $batch = $this->read($db, $f);
        self::assertNull($batch->unavailableReason);
        $group = $batch->skus[0]->days[0]->groups[0];
        self::assertCount(2, $group->parts);
        self::assertCount(1, $group->returns);
        self::assertSame('ALL-R', $group->returns[0]->sourceRowId);
        self::assertSame(20, $group->returns[0]->quantity);
        foreach ([$batch, $batch->skus[0], $batch->skus[0]->days[0], $group, ...$group->parts] as $node) {
            self::assertSame('0', $node->quantities?->currentD);
            self::assertSame('0', $node->quantities->eligibleOpenQuantity);
            self::assertSame('0', $node->quantities->expectedNetNumerator);
            self::assertSame('1', $node->quantities->expectedNetDenominator);
        }
        self::assertSame(['A', 'B'], array_keys($this->parts($batch)));
        $f->currentReturns($f->readAt, false);
        self::assertSame('returns_coverage_missing', $this->read($db, $f)->unavailableReason);
        self::assertNull($this->read($db, $f)->quantities);
    }

    public function testFullReturnRequiresDeliveredCapacityAtEveryPositiveDelta(): void
    {
        foreach ([[20, '-36 hours', false, 'contradictory_quantity'], [7, '-36 hours', true, null], [20, '-36 hours', true, 'contradictory_quantity'], [20, '-1 day', false, 'contradictory_quantity']] as [$first, $when, $revise, $reason]) {
            [$db, $f] = $this->fixture();
            $f->delivered('A', 'SKU', 7, $f->readAt->modify('-2 days'), 'ORDER');
            $f->delivered('B', 'SKU', 13, $f->readAt->modify('-1 day'), 'ORDER');
            $f->returned('ORDER', 'SKU', $first, $f->readAt->modify($when));
            if ($revise) {
                $f->returned('ORDER', 'SKU', 20, $f->readAt->modify('-1 hour'));
            }
            $f->currentReturns();
            $batch = $this->read($db, $f);
            self::assertSame($reason, $batch->unavailableReason, 'firstR='.$first.' at '.$when.' revise='.(int) $revise);
            $group = $batch->skus[0]->days[0]->groups[0];
            self::assertCount(1, $group->returns);
            self::assertSame($revise ? 2 : 1, $group->returns[0]->latestFactRevision);
            foreach ([$batch, $batch->skus[0], $batch->skus[0]->days[0], $group, ...$group->parts] as $node) {
                self::assertSame($reason, $node->unavailableReason);
                if (null === $reason) {
                    self::assertSame('0', $node->quantities?->expectedNetNumerator);
                    self::assertSame('1', $node->quantities->expectedNetDenominator);
                } else {
                    self::assertNull($node->quantities);
                }
            }
        }
    }

    public function testMissingDailyProofOrEpochNeverBecomesZero(): void
    {
        [$db, $f] = $this->fixture(training: true);
        $origin = $f->readAt->modify('-3 days');
        $f->delivered('D', 'SKU', 70, $origin);
        $f->daily($origin, [1]);
        $f->currentReturns();
        $result = $this->read($db, $f);
        self::assertSame('returns_coverage_missing', $result->unavailableReason);
        self::assertNull($result->quantities);
        $db->resetObservations();
        $clock = $this->clock($f);
        $missing = ($this->action($db, $f, $clock, false))($f->company->toRfc4122(), $f->account->toRfc4122(), $this->request($f));
        self::assertSame('source_history_unavailable', $missing->unavailableReason);
        self::assertSame([], $missing->skus);
        self::assertNull($missing->quantities);
        self::assertNull($missing->context);
        self::assertSame(1, $clock->calls);
        self::assertSame(0, $db->populations);
    }

    public function testCurrentDayPartialReceiptDoesNotProveEmptyWholeDayAndForeignScopeDoesNotLeak(): void
    {
        [$db, $f] = $this->fixture();
        $f->currentReturns();
        $day = $this->request($f)->from;
        $f->receipt(MarketplaceReportType::OzonPostingFboList, $day, $day, $f->readAt->modify('-1 second'));
        $emptyDay = $this->read($db, $f);
        self::assertNull($emptyDay->quantities);
        self::assertSame('source_history_unavailable', $emptyDay->unavailableReason);
        $this->terminalZero($f);
        self::assertSame('0', $this->read($db, $f)->quantities?->expectedNetNumerator);
        $foreign = ($this->action($db, $f, $this->clock($f)))(Uuid::v7()->toRfc4122(), $f->account->toRfc4122(), $this->request($f));
        self::assertNull($foreign->quantities);
        self::assertSame([], $this->parts($foreign));
    }

    /** @return array{NetForecastObservedConnection, NetTargetFixture} */
    private function fixture(bool $training = false): array
    {
        if (!self::$booted) {
            self::bootKernel();
        }
        $base = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $base);
        $db = DriverManager::getConnection(array_replace($base->getParams(), ['wrapperClass' => NetForecastObservedConnection::class]));
        self::assertInstanceOf(NetForecastObservedConnection::class, $db);
        $this->connections[] = $db;
        $database = $db->fetchOne('SELECT current_database()');
        self::assertIsString($database);
        self::assertMatchesRegularExpression('/^app_test(?:[1-9][0-9]*)?$/D', $database);
        if ($training) {
            $t = new NetTrainingFixture($db);
            $f = new NetTargetFixture($db, $t->readAt->modify('+123456 microseconds'), $t->company, $t->account);
            for ($i = 0; $i < 35; ++$i) {
                $t->posting('TRAIN-D-'.$i, 'SKU', 40);
                if ($i < 20) {
                    $t->returned('TRAIN-D-'.$i, 'SKU', 5, $t->terminal->modify('+10 days'));
                }
            }
            for ($i = 0; $i < 30; ++$i) {
                $t->posting('TRAIN-C-'.$i, 'SKU', 40, 'cancelled');
            }
            $t->returnCoverage();
        } else {
            $f = new NetTargetFixture($db);
        }
        $this->fixtures[] = $f;

        return [$db, $f];
    }

    private function other(Connection $db): Connection
    {
        $params = $db->getParams();
        unset($params['wrapperClass']);
        $other = DriverManager::getConnection($params);
        $this->connections[] = $other;
        self::assertNotSame($db->fetchOne('SELECT pg_backend_pid()'), $other->fetchOne('SELECT pg_backend_pid()'));

        return $other;
    }

    private function freshTargets(NetTargetFixture $f): void
    {
        $origin = $f->readAt->modify('-1 day');
        $f->delivered('D', 'SKU', 70, $origin);
        $f->sale('OPEN', 'SKU', 50, 'awaiting_packaging', $f->readAt->modify('-2 hours'));
        $f->daily($origin);
        $f->currentReturns();
    }

    private function terminalZero(NetTargetFixture $f): void
    {
        $f->sale('ZERO', 'SKU', 7, 'delivered', $f->readAt->modify('-2 hours'));
        $f->returned('ZERO', 'SKU', 7, $f->readAt->modify('-1 hour'));
        $f->currentReturns();
    }

    /** @param list<string> $skus */
    private function request(NetTargetFixture $f, array $skus = ['SKU']): NetTargetRequest
    {
        $day = new \DateTimeImmutable($f->readAt->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d'));

        return new NetTargetRequest($skus, $day, $day);
    }

    private function clock(NetTargetFixture $f, ?\Closure $before = null): NetForecastCountingClock
    {
        return new NetForecastCountingClock($f->readAt, $before);
    }

    private function action(Connection $db, NetTargetFixture $f, ClockInterface $clock, bool $epoch = true, NetTargetLimits $limits = new NetTargetLimits()): ReadNetForecastAction
    {
        $e = $f->epoch;
        $epochs = $epoch ? new NetForecastCaptureEpochs(json_encode([['epochId' => $e->id, 'captureVersion' => $e->captureVersion, 'startUtc' => $e->start->format('Y-m-d\TH:i:s\Z'), 'endUtc' => null, 'cutoverRelease' => $e->cutoverRelease]], \JSON_THROW_ON_ERROR)) : new NetForecastCaptureEpochs();
        $curves = new BuildReturnCurveAction();
        $pre = new BuildPreHandoverGrossRateAction();
        $post = new BuildPostHandoverGrossRateAction();
        $aggregate = new AggregateNetForecastAction(new AssessDeliveredRiskAction($curves), new AssessOpenRetentionAction($pre, $post, $curves, new ApplyOpenRetentionAction($pre, $post, $curves)));
        $training = new ReadNetTrainingAction($db, new GrossTrainingAggregateQuery($db), new ReturnTrainingAggregateQuery($db), $epochs, $clock);

        return new ReadNetForecastAction($db, $training, new NetTargetQuery($db), $aggregate, $limits);
    }

    private function read(Connection $db, NetTargetFixture $f): NetForecastBatch
    {
        return ($this->action($db, $f, $this->clock($f)))($f->company->toRfc4122(), $f->account->toRfc4122(), $this->request($f));
    }

    /** @return array<string, NetForecastPart> */
    private function parts(NetForecastBatch $batch): array
    {
        $parts = [];
        foreach ($batch->skus as $sku) {
            foreach ($sku->days as $day) {
                foreach ($day->groups as $group) {
                    foreach ($group->parts as $part) {
                        $parts[$part->sourceRowId] = $part;
                    }
                }
            }
        }

        return $parts;
    }
}

/** A real DBAL connection; observation/hooking only, never substitutes population rows. */
final class NetForecastObservedConnection extends Connection
{
    /** @var list<string> */
    public array $sql = [];
    public int $populations = 0;
    public int $transactionStarts = 0;
    /** @var list<string> */
    public array $populationSnapshots = [];
    /** @var list<string> */
    public array $populationReadAts = [];
    public ?\Closure $afterReturns = null;
    public ?string $timeoutPopulation = null;

    public function resetObservations(): void
    {
        $this->sql = [];
        $this->populations = 0;
        $this->transactionStarts = 0;
        $this->populationSnapshots = [];
        $this->populationReadAts = [];
    }

    public function beginTransaction(): void
    {
        ++$this->transactionStarts;
        parent::beginTransaction();
    }

    public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        $this->sql[] = $sql;
        $population = str_contains($sql, 'result.refusal_reason') ? 'target' : (str_contains($sql, 'result.age_profile') ? 'returns' : (str_contains($sql, 'result.p95_seconds') ? 'gross' : null));
        if (null !== $population) {
            ++$this->populations;
            $snapshot = $this->fetchOne('SELECT txid_current_snapshot()::text');
            ReadNetForecastActionTest::assertIsString($snapshot);
            ReadNetForecastActionTest::assertIsString($params['asOf'] ?? null);
            $this->populationSnapshots[] = $snapshot;
            $this->populationReadAts[] = $params['asOf'];
            ReadNetForecastActionTest::assertSame('off', $this->fetchOne('SHOW jit'));
            ReadNetForecastActionTest::assertSame('5s', $this->fetchOne('SHOW statement_timeout'));
            if ($this->timeoutPopulation === $population) {
                $this->timeoutPopulation = null;
                $this->executeStatement("SET LOCAL statement_timeout = '1ms'");

                // Real PostgreSQL cancellation marks the transaction aborted until guard rollback.
                return parent::executeQuery('SELECT pg_sleep(0.1)');
            }
        }
        $result = parent::executeQuery($sql, $params, $types, $qcp);
        if ('returns' === $population && null !== $this->afterReturns) {
            $hook = $this->afterReturns;
            $this->afterReturns = null;
            $hook();
        }

        return $result;
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        $this->sql[] = $sql;

        return parent::executeStatement($sql, $params, $types);
    }
}

final class NetForecastCountingClock implements ClockInterface
{
    public int $calls = 0;

    public function __construct(private readonly \DateTimeImmutable $readAt, private readonly ?\Closure $before = null)
    {
    }

    public function now(): \DateTimeImmutable
    {
        ++$this->calls;
        if (null !== $this->before) {
            ($this->before)();
        }

        return $this->readAt;
    }
}
