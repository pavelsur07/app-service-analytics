<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetBatch;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetLimits;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetQuery;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetRequest;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;
use App\Tests\Support\Ingestion\NetTargetFixture;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[SkipDatabaseRollback]
final class NetTargetQueryTest extends KernelTestCase
{
    /** @var list<NetTargetFixture> */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            $fixture->cleanup();
        }
        parent::tearDown();
    }

    public function testRealFreshAndOpenTargetsDoNotRequireGrossMaturity(): void
    {
        [$db, $f] = $this->fixture();
        $origin = $f->readAt->modify('-1 day');
        $f->delivered('D', 'SKU', 70, $origin);
        $f->sale('PRE', 'SKU', 50, 'awaiting_packaging', $f->readAt->modify('-2 hours'));
        $f->sale('POST', 'SKU', 40, 'delivering', $f->readAt->modify('-2 hours'));
        $f->daily($origin);
        $f->currentReturns();
        $batch = $this->read($db, $f);
        self::assertNull($batch->unavailableReason);
        $parts = array_merge(...array_map(static fn ($g): array => $g->parts, $batch->skus[0]->days[0]->groups));
        self::assertCount(3, $parts);
        $byId = array_column($parts, null, 'sourceRowId');
        self::assertSame(70, $byId['D']->currentD);
        self::assertEquals($origin, $byId['D']->deliveryOrigin);
        self::assertSame(50, $byId['PRE']->eligibleOpenPre);
        self::assertSame(40, $byId['POST']->eligibleOpenPost);
        self::assertSame('12345', $byId['D']->trace->amountMinor);
        self::assertSame(2, $byId['D']->trace->latestFactRevision);
        self::assertNotNull($byId['D']->trace->firstRevisionId);
        self::assertNotNull($byId['D']->trace->latestRevisionId);
        $f->currentReturns($f->readAt, false);
        self::assertSame('returns_coverage_missing', $this->read($db, $f)->unavailableReason);
    }

    public function testAllocationUsesAllSiblingSkuAndEarlierDayBeforeRequestedFilter(): void
    {
        [$db, $f] = $this->fixture();
        $origin = $f->readAt->modify('-1 day');
        $old = $f->readAt->modify('-1 day');
        $f->delivered('EARLY', 'SKU', 3, $origin, 'ORDER', $old);
        $f->delivered('CURRENT', 'SKU', 4, $origin, 'ORDER');
        $f->delivered('SIBLING', 'OTHER', 1, $origin, 'ORDER');
        $f->returned('ORDER', 'SKU', 5, $f->readAt->modify('-2 hours'));
        $f->sale('CANCEL', 'C', 2, 'awaiting_packaging', $origin->modify('-3 hours'), 'ORDER');
        $f->sale('CANCEL', 'C', 2, 'delivering', $origin->modify('-1 hour'), 'ORDER');
        $f->sale('CANCEL', 'C', 2, 'cancelled', $origin, 'ORDER');
        $f->returned('ORDER', 'C', 2, $f->readAt->modify('-2 hours'), 'C-RETURN', 'Cancellation', 'Покупатель отказался при вручении: товар не подошел');
        $f->daily($origin);
        $f->currentReturns();
        $day = new \DateTimeImmutable($f->readAt->format('Y-m-d'));
        $batch = $this->read($db, $f, new NetTargetRequest(['SKU', 'C'], $day, $day));
        self::assertNull($batch->unavailableReason);
        $skus = array_column($batch->skus, null, 'marketplaceSku');
        $part = $skus['SKU']->days[0]->groups[0]->parts[0];
        self::assertSame('CURRENT', $part->sourceRowId);
        self::assertSame(2, $part->currentD);
        self::assertSame(2, $part->knownR);
        self::assertNotNull($part->knownR);
        self::assertNotNull($part->t1);
        self::assertNotNull($part->t2);
        self::assertNotNull($part->p);
        self::assertNotNull($part->eligibleOpenPre);
        self::assertNotNull($part->eligibleOpenPost);
        self::assertNotNull($part->unknownQuantity);
        self::assertSame(4, $part->currentD + $part->knownR + $part->t1 + $part->t2 + $part->p + $part->eligibleOpenPre + $part->eligibleOpenPost + $part->unknownQuantity);
        self::assertSame(2, $skus['C']->days[0]->groups[0]->parts[0]->p, 'Delivered sibling OTHER outside the requested SKU makes refusal P.');
        $f->returned('ORDER', 'SKU', 8, $f->readAt->modify('-1 hour'));
        self::assertSame('contradictory_quantity', $this->read($db, $f)->unavailableReason);
    }

    public function testHistoricalOnlyMovedOutSeedCannotBecomeCompleteEmptyZero(): void
    {
        [$db, $f] = $this->fixture();
        $at = $f->readAt->modify('-3 hours');
        $f->sale('MOVED', 'SKU', 5, 'awaiting_packaging', $at, 'OLD');
        $f->sale('MOVED', 'SKU', 5, 'awaiting_packaging', $at->modify('+1 hour'), 'NEW');
        $f->currentReturns();
        self::assertSame('source_history_unavailable', $this->read($db, $f)->unavailableReason);
        // Adversarial corruption only: business date and SKU are immutable in the real writer.
        $db->executeStatement('UPDATE sales_fact SET marketplace_sku = ?, business_date = business_date + 1 WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?', ['OUTSIDE', $f->company->toRfc4122(), $f->account->toRfc4122(), 'MOVED']);
        self::assertSame('source_history_unavailable', $this->read($db, $f)->unavailableReason);
    }

    public function testFreshDailyGapsAndCurrentRefreshAreIndependent(): void
    {
        [$db, $f] = $this->fixture();
        $origin = $f->readAt->modify('-3 days');
        $f->delivered('D', 'SKU', 7, $origin);
        $f->daily($origin, [1]);
        $f->currentReturns();
        self::assertSame('returns_coverage_missing', $this->read($db, $f)->unavailableReason);
        $day = $origin->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(0, 0)->modify('+1 day');
        $f->receipt(\App\Ingestion\Domain\MarketplaceReportType::OzonReturnsList, $day, $day, $f->readAt->modify('-1 hour'));
        self::assertSame('returns_coverage_missing', $this->read($db, $f)->unavailableReason, 'Late catch-up cannot recover a missed timely day.');
        $timely = $day->modify('+1 day +1 hour')->setTimezone(new \DateTimeZone('UTC'));
        $f->receipt(\App\Ingestion\Domain\MarketplaceReportType::OzonReturnsList, $day, $day, $timely);
        self::assertNull($this->read($db, $f)->unavailableReason);
        $f->receipt(\App\Ingestion\Domain\MarketplaceReportType::OzonReturnsList, $day, $day, $timely, false);
        self::assertSame('returns_coverage_missing', $this->read($db, $f)->unavailableReason, 'Equal-time success/failure is ambiguous despite a later catch-up.');
    }

    public function testAgedReadinessNeedsItsOwn67DaysAndDeepWitness(): void
    {
        [$db, $f] = $this->fixture();
        $origin = $f->readAt->modify('-70 days');
        $f->delivered('AGED', 'SKU', 7, $origin);
        $f->daily($origin);
        $f->currentReturns();
        self::assertSame('target_readiness_missing', $this->read($db, $f)->unavailableReason);
        $f->receipt(\App\Ingestion\Domain\MarketplaceReportType::OzonReturnsList, $origin, $origin->modify('+60 days'), $origin->modify('+67 days -1 second'));
        self::assertSame('target_readiness_missing', $this->read($db, $f)->unavailableReason);
        $f->receipt(\App\Ingestion\Domain\MarketplaceReportType::OzonReturnsList, $origin, $origin->modify('+60 days'), $f->readAt->modify('+1 second'));
        self::assertSame('target_readiness_missing', $this->read($db, $f)->unavailableReason, 'Future deep completion is invisible.');
        $f->receipt(\App\Ingestion\Domain\MarketplaceReportType::OzonReturnsList, $origin, $origin->modify('+60 days'), $origin->modify('+68 days'));
        $batch = $this->read($db, $f);
        self::assertNull($batch->unavailableReason);
        $proof = $batch->skus[0]->days[0]->groups[0]->parts[0]->readiness;
        self::assertNotNull($proof);
        self::assertEquals($origin, $proof->deliveryOrigin);
        self::assertGreaterThanOrEqual($origin->modify('+67 days'), $proof->coverageThrough);
        self::assertNotNull($proof->deepCompletedAt);
    }

    public function testNoSyntheticOriginAndAmbiguousReturnOrigins(): void
    {
        [$db, $f] = $this->fixture();
        $f->sale('INITIAL', 'SKU', 5, 'delivered', $f->readAt->modify('-2 hours'));
        $f->currentReturns();
        self::assertSame('delivery_observation_unknown', $this->read($db, $f)->unavailableReason);
        $f->returned('INITIAL', 'SKU', 5, $f->readAt->modify('-1 hour'));
        $zero = $this->read($db, $f);
        self::assertNull($zero->unavailableReason, 'Proven initial delivered+ClientReturn zero does not need a fabricated origin or training.');
        self::assertSame(0, $zero->skus[0]->days[0]->groups[0]->parts[0]->currentD);
        $f->delivered('A', 'SKU', 5, $f->readAt->modify('-2 days'), 'MIX');
        $f->delivered('B', 'SKU', 5, $f->readAt->modify('-1 day'), 'MIX');
        $f->daily($f->readAt->modify('-2 days'));
        $f->returned('MIX', 'SKU', 1, $f->readAt->modify('-1 hour'), 'MIX-RETURN');
        self::assertSame('ambiguous_delivery_allocation', $this->read($db, $f)->unavailableReason);
    }

    public function testDifferentOriginsOnlyPermitProvenTerminalZero(): void
    {
        foreach ([[20, '-1 hour', null], [1, '-1 hour', 'ambiguous_delivery_allocation'], [21, '-1 hour', 'contradictory_quantity'], [20, '-3 days', 'contradictory_quantity']] as [$returned, $when, $reason]) {
            [$db, $f] = $this->fixture();
            $f->delivered('A', 'SKU', 7, $f->readAt->modify('-2 days'), 'ORDER');
            $f->delivered('B', 'SKU', 13, $f->readAt->modify('-1 day'), 'ORDER');
            $f->returned('ORDER', 'SKU', $returned, $f->readAt->modify($when));
            $f->currentReturns();
            $batch = $this->read($db, $f);
            self::assertSame($reason, $batch->unavailableReason, 'R='.$returned.' at '.$when);
            $parts = $batch->skus[0]->days[0]->groups[0]->parts;
            self::assertCount(2, $parts);
            foreach ($parts as $part) {
                self::assertSame($reason, $part->unavailableReason);
                self::assertSame(null === $reason ? 0 : null, $part->currentD);
            }
            if (null === $reason) {
                self::assertSame([7, 13], array_column($parts, 'knownR'));
            }
        }
    }

    public function testFullReturnCapacityCannotBeBorrowedFromFutureDelivery(): void
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
            $parts = $batch->skus[0]->days[0]->groups[0]->parts;
            self::assertCount(2, $parts);
            foreach ($parts as $part) {
                self::assertSame($reason, $part->unavailableReason);
                self::assertSame(null === $reason ? 0 : null, $part->currentD);
            }
        }
    }

    public function testInitialDeliveredCapacityNeedsItsAcceptedWitnessWithoutInventingAnOrigin(): void
    {
        foreach ([['-3 hours', null], ['-1 hour', 'contradictory_quantity']] as [$initialAt, $reason]) {
            [$db, $f] = $this->fixture();
            $f->delivered('A', 'SKU', 7, $f->readAt->modify('-2 days'), 'ORDER');
            $f->delivered('B', 'SKU', 13, $f->readAt->modify('-1 day'), 'ORDER');
            $f->sale('INITIAL', 'SKU', 5, 'delivered', $f->readAt->modify($initialAt), 'ORDER');
            $f->returned('ORDER', 'SKU', 25, $f->readAt->modify('-2 hours'));
            $f->currentReturns();
            $batch = $this->read($db, $f);
            self::assertSame($reason, $batch->unavailableReason);
            $parts = array_column($batch->skus[0]->days[0]->groups[0]->parts, null, 'sourceRowId');
            self::assertNull($parts['INITIAL']->deliveryOrigin, 'The accepted delivered witness supplies capacity, never a synthetic model age.');
            self::assertSame(null === $reason ? 0 : null, $parts['INITIAL']->currentD);
        }
    }

    public function testNestedAndGlobalBudgetsRejectWholeBatchBeforeJson(): void
    {
        [$db, $f] = $this->fixture();
        $f->sale('A', 'SKU', 1, 'awaiting_packaging', $f->readAt->modify('-2 hours'));
        $f->sale('B', 'SKU', 1, 'awaiting_packaging', $f->readAt->modify('-2 hours'));
        $f->currentReturns();
        foreach ([new NetTargetLimits(groups: 1), new NetTargetLimits(sourceKeys: 1), new NetTargetLimits(statuses: 1), new NetTargetLimits(receipts: 1), new NetTargetLimits(groupsPerSkuDay: 1), new NetTargetLimits(outputBytes: 1), new NetTargetLimits(phpAllocationBytes: 1)] as $limits) {
            $batch = $this->read($db, $f, limits: $limits);
            self::assertSame('input_budget_exceeded', $batch->unavailableReason);
            self::assertSame([], $batch->skus, 'No JSON/partial source hierarchy may escape a batch refusal.');
            self::assertNotNull($batch->budgetFailure);
        }
    }

    public function testMissingAcceptedSiblingHistoryCannotProveAnotherSkuOrEmptyZero(): void
    {
        [$db, $f] = $this->fixture();
        $f->sale('VALID', 'SKU', 1, 'awaiting_packaging', $f->readAt->modify('-2 hours'), 'ORDER');
        $f->sale('BROKEN', 'OTHER', 1, 'awaiting_packaging', $f->readAt->modify('-2 hours'), 'ORDER');
        $f->currentReturns();
        // Adversarial corruption: accepted writer always emits this revision atomically.
        $db->executeStatement('DELETE FROM sales_fact_revision WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?', [$f->company->toRfc4122(), $f->account->toRfc4122(), 'BROKEN']);
        self::assertSame('source_history_unavailable', $this->read($db, $f)->unavailableReason);
    }

    public function testMapperRequiresExactRequestedSkuDaySpine(): void
    {
        [$db, $f] = $this->fixture();
        $f->sale('OPEN', 'SKU', 1, 'awaiting_packaging', $f->readAt->modify('-2 hours'));
        $f->currentReturns();
        [$context, $request, $rows] = $this->rows($db, $f);
        $query = new NetTargetQuery($db);
        self::assertSame('source_history_unavailable', $query->mapRows($context, $request, [])->unavailableReason);
        $cases = [[$rows[0], $rows[0]]];
        $foreign = $rows;
        $foreign[0]['marketplace_sku'] = 'FOREIGN';
        $cases[] = $foreign;
        foreach ([[], ['businessDate' => '1900-01-01'], ['businessDate' => $request->from->format('Y-m-d')]] as $replacement) {
            $changed = $rows;
            self::assertIsString($changed[0]['days']);
            $decoded = json_decode($changed[0]['days'], true, 24, \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            if ([] === $replacement) {
                $decoded = [];
            } else {
                self::assertIsArray($decoded[0]);
                $decoded[0]['businessDate'] = $replacement['businessDate'];
                if ($replacement['businessDate'] === $request->from->format('Y-m-d')) {
                    $decoded[] = $decoded[0];
                }
            }
            $changed[0]['days'] = json_encode($decoded, \JSON_THROW_ON_ERROR);
            $cases[] = $changed;
        }
        foreach ($cases as $bad) {
            self::assertSame('source_history_unavailable', $query->mapRows($context, $request, $bad)->unavailableReason);
        }
        $changed = $rows;
        $changed[0]['epoch_start'] = $f->epoch->start->modify('+1 microsecond')->format('U.u');
        self::assertSame('training_context_mismatch', $query->mapRows($context, $request, $changed)->unavailableReason);
        $this->expectException(\InvalidArgumentException::class);
        $query->mapRows($context, new NetTargetRequest([], $request->from, $request->to), []);
    }

    public function testForeignScopeRejectedBeforeZeroAndTrace(): void
    {
        [$db, $f] = $this->fixture();
        $f->sale('SAME', 'SKU', 1, 'awaiting_packaging', $f->readAt->modify('-2 hours'));
        $f->currentReturns();
        $other = new NetTargetFixture($db, company: \Symfony\Component\Uid\Uuid::v7(), account: $f->account);
        $this->fixtures[] = $other;
        $other->sale('SAME', 'SKU', 999, 'awaiting_packaging', $other->readAt->modify('-2 hours'));
        $other->currentReturns($other->readAt, false);
        self::assertSame(1, $this->read($db, $f)->skus[0]->days[0]->groups[0]->parts[0]->eligibleOpenPre);
        $context = new NetTrainingContext($f->company->toRfc4122(), $f->account->toRfc4122(), $f->readAt, $f->epoch, 'test-current-context');
        $day = new \DateTimeImmutable($f->readAt->format('Y-m-d'));
        foreach ([[$other->company->toRfc4122(), $context->accountId], [$context->companyId, \Symfony\Component\Uid\Uuid::v7()->toRfc4122()]] as [$company, $account]) {
            foreach ([['SKU'], []] as $skus) {
                try {
                    (new NetTargetQuery($db))->build($company, $account, $context, new NetTargetRequest($skus, $day, $day));
                    self::fail('Foreign context must fail before source metadata or empty handling.');
                } catch (\InvalidArgumentException $e) {
                    self::assertSame('Training context belongs to another company or account.', $e->getMessage());
                }
            }
        }
    }

    public function testRealCoveredEmptyDayDiffersFromMissingAndFutureDay(): void
    {
        [$db, $f] = $this->fixture();
        $day = $f->readAt->modify('-2 days');
        $request = new NetTargetRequest(['SKU'], $day, $day);
        $f->currentReturns();
        self::assertSame('postings_coverage_missing', $this->read($db, $f, $request)->unavailableReason);
        $f->receipt(\App\Ingestion\Domain\MarketplaceReportType::OzonPostingFboList, $day, $day, $f->readAt->modify('-1 hour'));
        $zero = $this->read($db, $f, $request);
        self::assertNull($zero->unavailableReason);
        self::assertTrue($zero->skus[0]->days[0]->emptyComplete);
        self::assertSame([], $zero->skus[0]->days[0]->groups);
        $future = $f->readAt->modify('+1 day');
        self::assertNotNull($this->read($db, $f, new NetTargetRequest(['SKU'], $future, $future))->unavailableReason);
    }

    public function testFreshOriginTodayAndExact60And67Boundaries(): void
    {
        [$db, $f] = $this->fixture();
        $origin = $f->readAt->modify('-60 days');
        $f->delivered('D', 'SKU', 7, $origin);
        $f->daily($origin);
        $f->currentReturns();
        self::assertNull($this->read($db, $f, readAt: $f->readAt->modify('-1 microsecond'))->unavailableReason);
        self::assertSame('target_horizon_not_ready', $this->read($db, $f)->unavailableReason);
        self::assertSame('target_horizon_not_ready', $this->read($db, $f, readAt: $f->readAt->modify('+1 microsecond'))->unavailableReason);
        $futureCurrent = $origin->modify('+67 days');
        $f->receipt(\App\Ingestion\Domain\MarketplaceReportType::OzonReturnsList, $futureCurrent, $futureCurrent, $futureCurrent->modify('-1 second'));
        self::assertSame('target_horizon_not_ready', $this->read($db, $f, readAt: $origin->modify('+67 days -1 microsecond'))->unavailableReason);
        self::assertNotNull($this->read($db, $f, readAt: $origin->modify('+67 days'))->unavailableReason);
        self::assertNotNull($this->read($db, $f, readAt: $origin->modify('+67 days +1 microsecond'))->unavailableReason);
    }

    public function testNoBackfillOriginOrQuantityCorrectionAfterDelivery(): void
    {
        [$db, $f] = $this->fixture();
        $origin = $f->readAt->modify('-2 days');
        $f->delivered('D', 'SKU', 7, $origin);
        $f->daily($origin);
        $f->currentReturns();
        $f->sale('D', 'SKU', 8, 'delivered', $f->readAt->modify('-2 hours'));
        self::assertSame('contradictory_quantity', $this->read($db, $f)->unavailableReason);
    }

    public function testCombinedSourcesAndRevisionLimitsAndOversizedEscapedIdentity(): void
    {
        [$db, $f] = $this->fixture();
        $f->delivered('D', 'SKU', 7, $f->readAt->modify('-1 day'));
        $f->returned('D', 'SKU', 1, $f->readAt->modify('-2 hours'));
        $f->daily($f->readAt->modify('-1 day'));
        $f->currentReturns();
        foreach ([new NetTargetLimits(sourceKeys: 1), new NetTargetLimits(sourcesPerGroup: 1), new NetTargetLimits(revisions: 2), new NetTargetLimits(revisionsPerSource: 1), new NetTargetLimits(statusesPerGroup: 1)] as $limits) {
            self::assertSame('input_budget_exceeded', $this->read($db, $f, limits: $limits)->unavailableReason);
        }
        $key = str_repeat('"\\', 100000);
        $f->sale($key, 'SKU', 1, 'awaiting_packaging', $f->readAt->modify('-2 hours'), 'BIG', 'BIG');
        $batch = $this->read($db, $f, limits: new NetTargetLimits(outputBytes: 2000000));
        self::assertSame('input_budget_exceeded', $batch->unavailableReason, 'Repeated escaped source identity must be budgeted before JSON.');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFullCalendarSpineFitsSqlTransferBudgetBeforeAnyGroup(): void
    {
        [$db, $f] = $this->fixture();
        $from = $f->readAt->modify('-184 days');
        $to = $f->readAt->modify('-1 day');
        $f->receipt(\App\Ingestion\Domain\MarketplaceReportType::OzonPostingFboList, $from, $to, $f->readAt->modify('-1 hour'));
        $f->currentReturns();
        $request = new NetTargetRequest(array_map(static fn (int $i): string => 'SKU-'.$i, range(0, 49)), $from, $to);
        $db->beginTransaction();
        try {
            $db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $snapshot = $db->fetchOne('SELECT txid_current_snapshot()::text');
            self::assertIsString($snapshot);
            $context = new NetTrainingContext($f->company->toRfc4122(), $f->account->toRfc4122(), $f->readAt, $f->epoch, $snapshot);
            $rows = (new NetTargetQuery($db))->build($context->companyId, $context->accountId, $context, $request)->executeQuery()->fetchAllAssociative();
            self::assertCount(50, $rows);
            foreach ($rows as $row) {
                self::assertNull($row['refusal_reason']);
                self::assertIsString($row['output_bytes']);
                self::assertIsString($row['allocation_bytes']);
                self::assertLessThanOrEqual(33554432, (int) $row['output_bytes']);
                self::assertLessThanOrEqual(50331648, (int) $row['allocation_bytes']);
                self::assertIsString($row['days']);
                $days = json_decode($row['days'], true, 24, \JSON_THROW_ON_ERROR);
                self::assertIsArray($days);
                self::assertCount(184, $days);
            }
        } finally {
            $db->rollBack();
        }
    }

    public function testMapperMemoryDeadlineAndScalarRefusalsRemainWholeBatch(): void
    {
        [$db, $f] = $this->fixture();
        $f->sale('OPEN', 'SKU', 1, 'awaiting_packaging', $f->readAt->modify('-2 hours'));
        $f->currentReturns();
        [$context, $request, $rows] = $this->rows($db, $f);
        foreach ([new NetTargetLimits(phpPeakBytes: 1), new NetTargetLimits(computeSeconds: 0.000000001)] as $limits) {
            $batch = (new NetTargetQuery($db, $limits))->mapRows($context, $request, $rows);
            self::assertSame('input_budget_exceeded', $batch->unavailableReason);
            self::assertSame([], $batch->skus);
            self::assertNotNull($batch->budgetFailure);
            self::assertSame(1, $batch->budgetFailure->limit);
            self::assertGreaterThan($batch->budgetFailure->limit, $batch->budgetFailure->observedLowerBound);
        }
        $bad = $rows;
        $bad[0]['days'] = str_repeat('x', 1000000);
        self::assertSame('input_budget_exceeded', (new NetTargetQuery($db))->mapRows($context, $request, $bad)->unavailableReason);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGroupsAreLimitedPerSkuDayRatherThanAcrossTheRange(): void
    {
        [$db, $f] = $this->fixture();
        $previous = $f->readAt->modify('-1 day');
        for ($i = 0; $i < 101; ++$i) {
            $f->sale('TODAY-'.$i, 'SKU', 1, 'awaiting_packaging', $f->readAt->modify('-2 hours'));
            $f->sale('PREVIOUS-'.$i, 'SKU', 1, 'awaiting_packaging', $f->readAt->modify('-3 hours'), day: $previous);
        }
        $f->currentReturns();
        $request = new NetTargetRequest(['SKU'], $previous, $f->readAt);
        $batch = $this->read($db, $f, $request);
        self::assertNull($batch->unavailableReason, json_encode(['failure' => $batch->budgetFailure, 'usage' => memory_get_usage(true), 'peak' => memory_get_peak_usage(true)], \JSON_THROW_ON_ERROR));
        self::assertCount(101, $batch->skus[0]->days[0]->groups);
        self::assertCount(101, $batch->skus[0]->days[1]->groups);
        for ($i = 101; $i < 201; ++$i) {
            $f->sale('TODAY-'.$i, 'SKU', 1, 'awaiting_packaging', $f->readAt->modify('-2 hours'));
        }
        $overflow = $this->read($db, $f, $request);
        self::assertSame('input_budget_exceeded', $overflow->unavailableReason);
        self::assertSame('groups_per_sku_day', $overflow->budgetFailure?->input);
    }

    public function testSourceParts129RefuseWholeBatch(): void
    {
        [$db, $f] = $this->fixture();
        for ($i = 0; $i < 129; ++$i) {
            $f->sale('PART-'.$i, 'SKU', 1, 'awaiting_packaging', $f->readAt->modify('-2 hours'), 'ONE');
        }
        $f->currentReturns();
        $batch = $this->read($db, $f);
        self::assertSame('input_budget_exceeded', $batch->unavailableReason);
        self::assertSame('sources_per_group', $batch->budgetFailure?->input);
    }

    public function testOldReturnLinkAndExternalSiblingAliasCannotHideInCurrentMembership(): void
    {
        [$db, $f] = $this->fixture();
        $origin = $f->readAt->modify('-1 day');
        $f->delivered('SEED', 'SKU', 7, $origin, 'SEED-ORDER');
        $f->returned('SEED-ORDER', 'SKU', 1, $f->readAt->modify('-3 hours'));
        $f->returned('EXTERNAL', 'SKU', 1, $f->readAt->modify('-2 hours'));
        $f->daily($origin);
        $f->currentReturns();
        self::assertSame('source_history_unavailable', $this->read($db, $f)->unavailableReason);
    }

    public function testMissingOrderRemainsDiagnostic(): void
    {
        [$db, $f] = $this->fixture();
        $f->sale('NO-ORDER', 'SKU', 7, 'awaiting_packaging', $f->readAt->modify('-2 hours'), missingOrder: true);
        $f->currentReturns();
        $batch = $this->read($db, $f);
        self::assertNotNull($batch->unavailableReason);
        self::assertNull($batch->skus[0]->days[0]->groups[0]->orderNumber);
        self::assertNull($batch->skus[0]->days[0]->groups[0]->parts[0]->currentD);
        self::assertSame('NO-ORDER', $batch->skus[0]->days[0]->groups[0]->parts[0]->sourceRowId);
    }

    public function testUnknownReturnTypeAndNonMonotoneClientCorrectionsHaveExplicitReasons(): void
    {
        [$db, $f] = $this->fixture();
        $origin = $f->readAt->modify('-2 days');
        $f->delivered('D', 'SKU', 7, $origin);
        $f->daily($origin);
        $f->currentReturns();
        $f->returned('D', 'SKU', 2, $f->readAt->modify('-3 hours'), type: 'UnknownType');
        self::assertSame('unknown_outcome', $this->read($db, $f)->unavailableReason);
    }

    public function testClientReturnDecreaseIsContradictoryRatherThanClipped(): void
    {
        [$db, $f] = $this->fixture();
        $origin = $f->readAt->modify('-2 days');
        $f->delivered('D', 'SKU', 7, $origin);
        $f->daily($origin);
        $f->currentReturns();
        $f->returned('D', 'SKU', 2, $f->readAt->modify('-3 hours'));
        $f->returned('D', 'SKU', 1, $f->readAt->modify('-2 hours'));
        self::assertSame('contradictory_quantity', $this->read($db, $f)->unavailableReason);
    }

    public function testMapperRejectsUnexpectedNestedFieldsAndFutureTraceBeforeEvidence(): void
    {
        [$db, $f] = $this->fixture();
        $f->sale('OPEN', 'SKU', 1, 'awaiting_packaging', $f->readAt->modify('-2 hours'));
        $f->currentReturns();
        [$context, $request, $rows] = $this->rows($db, $f);
        self::assertIsString($rows[0]['days']);
        $decoded = json_decode($rows[0]['days'], true, 24, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded[0]);
        self::assertIsArray($decoded[0]['groups']);
        self::assertIsArray($decoded[0]['groups'][0]);
        self::assertIsArray($decoded[0]['groups'][0]['parts']);
        self::assertIsArray($decoded[0]['groups'][0]['parts'][0]);
        self::assertIsArray($decoded[0]['groups'][0]['parts'][0]['trace']);
        $unexpected = $decoded;
        $unexpected[0]['groups'][0]['parts'][0]['trace']['arbitraryNested'] = ['hidden' => ['buffer']];
        $future = $decoded;
        $future[0]['groups'][0]['parts'][0]['trace']['latestCapturedAt'] = $f->readAt->modify('+1 second')->format('Y-m-d H:i:s');
        foreach ([$unexpected, $future] as $malformed) {
            $bad = $rows;
            $bad[0]['days'] = json_encode($malformed, \JSON_THROW_ON_ERROR);
            self::assertSame('source_history_unavailable', (new NetTargetQuery($db))->mapRows($context, $request, $bad)->unavailableReason);
        }
    }

    public function testObservedStatusCanPrecedeItsAcceptedCapture(): void
    {
        [$db, $f] = $this->fixture();
        $origin = $f->readAt->modify('-1 day');
        $f->sale('D', 'SKU', 7, 'awaiting_packaging', $origin->modify('-2 hours'));
        $f->sale('D', 'SKU', 7, 'delivered', $origin->modify('+1 second'), observedAt: $origin);
        $f->daily($origin);
        $f->currentReturns();
        $batch = $this->read($db, $f);
        self::assertNull($batch->unavailableReason);
        self::assertEquals($origin, $batch->skus[0]->days[0]->groups[0]->parts[0]->deliveryOrigin);
    }

    public function testUnknownPredecessorCannotCreateADeliveryOrigin(): void
    {
        [$db, $f] = $this->fixture();
        $origin = $f->readAt->modify('-1 day');
        $f->sale('D', 'SKU', 7, 'unknown', $origin->modify('-2 hours'));
        $f->sale('D', 'SKU', 7, 'delivered', $origin);
        $f->daily($origin);
        $f->currentReturns();
        $batch = $this->read($db, $f);
        self::assertSame('unknown_outcome', $batch->unavailableReason);
        self::assertNull($batch->skus[0]->days[0]->groups[0]->parts[0]->deliveryOrigin);
    }

    public function testTodayOriginNeedsOnlyCurrentRefreshAndInitialPolling(): void
    {
        [$db, $f] = $this->fixture();
        $origin = $f->readAt->modify('-1 hour');
        $f->delivered('TODAY', 'SKU', 7, $origin);
        $f->currentReturns();
        $batch = $this->read($db, $f);
        self::assertNull($batch->unavailableReason);
        self::assertSame(7, $batch->skus[0]->days[0]->groups[0]->parts[0]->currentD);
        self::assertNull($batch->skus[0]->days[0]->groups[0]->parts[0]->readiness);
    }

    public function testMultiHopHistoricalSiblingAliasRefusesWithoutUnboundedExpansion(): void
    {
        [$db, $f] = $this->fixture();
        $at = $f->readAt->modify('-4 hours');
        $f->sale('A', 'SKU', 1, 'awaiting_packaging', $at, 'ROOT');
        $f->sale('B', 'OTHER', 1, 'awaiting_packaging', $at, 'ROOT');
        $f->sale('B', 'OTHER', 1, 'awaiting_packaging', $at->modify('+1 hour'), 'EXTERNAL');
        $f->sale('C', 'OTHER', 1, 'awaiting_packaging', $at, 'EXTERNAL');
        $f->sale('C', 'OTHER', 1, 'awaiting_packaging', $at->modify('+1 hour'), 'FAR');
        $f->currentReturns();
        $batch = $this->read($db, $f);
        self::assertSame('source_history_unavailable', $batch->unavailableReason);
        self::assertSame([], $batch->skus);
    }

    public function testInitialDeliveredQuantityCorrectionCannotBecomeTerminalZero(): void
    {
        [$db, $f] = $this->fixture();
        $f->sale('D', 'SKU', 7, 'delivered', $f->readAt->modify('-4 hours'));
        $f->sale('D', 'SKU', 5, 'delivered', $f->readAt->modify('-3 hours'));
        $f->returned('D', 'SKU', 5, $f->readAt->modify('-2 hours'));
        $f->currentReturns();
        self::assertSame('contradictory_quantity', $this->read($db, $f)->unavailableReason);
    }

    public function testCurrentReturnWithoutAcceptedHistoryCannotDisappearAsZeroReturns(): void
    {
        [$db, $f] = $this->fixture();
        $origin = $f->readAt->modify('-1 day');
        $f->delivered('D', 'SKU', 7, $origin);
        $f->returned('D', 'SKU', 3, $f->readAt->modify('-2 hours'));
        $f->daily($origin);
        $f->currentReturns();
        // Adversarial capture corruption: real writer emitted the immutable revision atomically.
        $db->executeStatement('DELETE FROM marketplace_return_fact_revision WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?', [$f->company->toRfc4122(), $f->account->toRfc4122(), 'RETURN']);
        $batch = $this->read($db, $f);
        self::assertSame('source_history_unavailable', $batch->unavailableReason);
        self::assertSame([], $batch->skus);
    }

    public function testHistoricalReturnWithoutCurrentFactCannotDisappearFromClosure(): void
    {
        [$db, $f] = $this->fixture();
        $origin = $f->readAt->modify('-1 day');
        $f->delivered('D', 'SKU', 7, $origin);
        $f->returned('D', 'SKU', 3, $f->readAt->modify('-2 hours'));
        $f->daily($origin);
        $f->currentReturns();
        // Adversarial current-fact corruption: historical membership must still stay visible to closure.
        $db->executeStatement('DELETE FROM marketplace_return_fact WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ?', [$f->company->toRfc4122(), $f->account->toRfc4122(), 'RETURN']);
        $batch = $this->read($db, $f);
        self::assertSame('source_history_unavailable', $batch->unavailableReason);
        self::assertSame([], $batch->skus);
    }

    public function testSiblingReturnChainGapCannotHideBehindRequestedSkuFilter(): void
    {
        [$db, $f] = $this->fixture();
        $origin = $f->readAt->modify('-1 day');
        $f->delivered('A', 'SKU', 1, $origin, 'ORDER');
        $f->delivered('B', 'OTHER', 7, $origin, 'ORDER');
        $f->returned('ORDER', 'OTHER', 2, $f->readAt->modify('-3 hours'));
        $f->returned('ORDER', 'OTHER', 3, $f->readAt->modify('-2 hours'));
        $f->daily($origin);
        $f->currentReturns();
        // Adversarial immutable-ledger gap; current and latest revision still agree.
        $db->executeStatement('DELETE FROM marketplace_return_fact_revision WHERE company_id = ? AND marketplace_account_id = ? AND source_row_id = ? AND fact_revision = 1', [$f->company->toRfc4122(), $f->account->toRfc4122(), 'RETURN']);
        self::assertSame('source_history_unavailable', $this->read($db, $f)->unavailableReason);
    }

    public function testClientReturnCannotHideUnknownOriginDeliveredCapacity(): void
    {
        [$db, $f] = $this->fixture();
        $origin = $f->readAt->modify('-1 day');
        $f->sale('A-INITIAL', 'SKU', 5, 'delivered', $origin->modify('-1 hour'), 'MIXED');
        $f->delivered('B-OBSERVED', 'SKU', 5, $origin, 'MIXED');
        $f->returned('MIXED', 'SKU', 5, $f->readAt->modify('-2 hours'));
        $f->daily($origin);
        $f->currentReturns();
        $batch = $this->read($db, $f);
        self::assertSame('ambiguous_delivery_allocation', $batch->unavailableReason, json_encode(array_map(static fn ($part): array => ['source' => $part->sourceRowId, 'currentD' => $part->currentD, 'knownR' => $part->knownR, 'origin' => $part->deliveryOrigin?->format('U.u')], $batch->skus[0]->days[0]->groups[0]->parts), \JSON_THROW_ON_ERROR));
        foreach ($batch->skus[0]->days[0]->groups[0]->parts as $part) {
            self::assertFalse($part->inputComplete);
            self::assertNull($part->currentD);
            self::assertNull($part->knownR);
        }
    }

    public function testUnknownOriginDeliveredOnEarlierDayStaysInGroupProof(): void
    {
        [$db, $f] = $this->fixture();
        $origin = $f->readAt->modify('-1 day');
        $f->sale('A-INITIAL', 'SKU', 5, 'delivered', $origin->modify('-1 hour'), 'MIXED', day: $f->readAt->modify('-1 day'));
        $f->delivered('B-OBSERVED', 'SKU', 5, $origin, 'MIXED');
        $f->returned('MIXED', 'SKU', 5, $f->readAt->modify('-2 hours'));
        $f->daily($origin);
        $f->currentReturns();
        $batch = $this->read($db, $f);
        self::assertSame('ambiguous_delivery_allocation', $batch->unavailableReason);
        self::assertCount(1, $batch->skus[0]->days[0]->groups[0]->parts);
        self::assertSame('B-OBSERVED', $batch->skus[0]->days[0]->groups[0]->parts[0]->sourceRowId);
        self::assertFalse($batch->skus[0]->days[0]->groups[0]->parts[0]->inputComplete);
        self::assertNull($batch->skus[0]->days[0]->groups[0]->parts[0]->currentD);
    }

    /** @return array{NetTrainingContext, NetTargetRequest, list<array<string, mixed>>} */
    private function rows(Connection $db, NetTargetFixture $f): array
    {
        $day = new \DateTimeImmutable($f->readAt->format('Y-m-d'));
        $request = new NetTargetRequest(['SKU'], $day, $day);
        $db->beginTransaction();
        try {
            $db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $snapshot = $db->fetchOne('SELECT txid_current_snapshot()::text');
            self::assertIsString($snapshot);
            $context = new NetTrainingContext($f->company->toRfc4122(), $f->account->toRfc4122(), $f->readAt, $f->epoch, $snapshot);
            $db->executeStatement("SET LOCAL jit = 'off'");
            $rows = (new NetTargetQuery($db))->build($context->companyId, $context->accountId, $context, $request)->executeQuery()->fetchAllAssociative();

            return [$context, $request, $rows];
        } finally {
            $db->rollBack();
        }
    }

    /** @return array{Connection, NetTargetFixture} */
    private function fixture(): array
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $database = $db->fetchOne('SELECT current_database()');
        self::assertIsString($database);
        self::assertMatchesRegularExpression('/^app_test(?:[1-9][0-9]*)?$/D', $database);
        $fixture = new NetTargetFixture($db);
        $this->fixtures[] = $fixture;

        return [$db, $fixture];
    }

    private function read(Connection $db, NetTargetFixture $f, ?NetTargetRequest $request = null, ?NetTargetLimits $limits = null, ?\DateTimeImmutable $readAt = null): NetTargetBatch
    {
        $day = new \DateTimeImmutable($f->readAt->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d'));
        $request ??= new NetTargetRequest(['SKU'], $day, $day);
        $query = new NetTargetQuery($db, $limits ?? new NetTargetLimits());
        $db->beginTransaction();
        try {
            $db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $snapshot = $db->fetchOne('SELECT txid_current_snapshot()::text');
            self::assertIsString($snapshot);
            $context = new NetTrainingContext($f->company->toRfc4122(), $f->account->toRfc4122(), $readAt ?? $f->readAt, $f->epoch, $snapshot);
            $db->executeStatement("SET LOCAL jit = 'off'");
            $rows = $query->build($context->companyId, $context->accountId, $context, $request)->executeQuery()->fetchAllAssociative();

            return $query->mapRows($context, $request, $rows);
        } finally {
            $db->rollBack();
        }
    }
}
