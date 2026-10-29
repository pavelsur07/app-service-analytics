<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Application\NetForecast\AggregateNetForecastAction;
use App\Ingestion\Application\NetForecast\ApplyOpenRetentionAction;
use App\Ingestion\Application\NetForecast\AssessDeliveredRiskAction;
use App\Ingestion\Application\NetForecast\AssessOpenRetentionAction;
use App\Ingestion\Application\NetForecast\BuildPostHandoverGrossRateAction;
use App\Ingestion\Application\NetForecast\BuildPreHandoverGrossRateAction;
use App\Ingestion\Application\NetForecast\BuildReturnCurveAction;
use App\Ingestion\Application\NetForecast\NetForecastBatch;
use App\Ingestion\Application\NetForecast\NetForecastPart;
use App\Ingestion\Application\NetForecast\NetForecastSeriesRequest;
use App\Ingestion\Application\NetForecast\NetTrainingBatch;
use App\Ingestion\Domain\ValueObject\NetForecastCaptureEpoch;
use App\Ingestion\Infrastructure\Query\NetForecast\GrossTrainingAggregateRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetBatch;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetDayRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetGroupRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetLimits;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetPartRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetReadinessRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetReturnTrace;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetSkuRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTargetSourceTrace;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingBudgetFailure;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;
use App\Ingestion\Infrastructure\Query\NetForecast\ReturnTrainingAgeRow;
use App\Ingestion\Infrastructure\Query\NetForecast\ReturnTrainingAggregateRow;
use PHPUnit\Framework\TestCase;

final class AggregateNetForecastActionTest extends TestCase
{
    private const string COMPANY = '01900000-0000-7000-8000-000000000001';
    private const string ACCOUNT = '01900000-0000-7000-8000-000000000002';
    private const string FOREIGN = '01900000-0000-7000-8000-000000000003';
    private const string SKU = '00123';
    private const string DAY = '2026-10-08';

    protected function setUp(): void
    {
        self::assertTrue(class_exists(AggregateNetForecastAction::class), 'Pure exact aggregate must exist.');
    }

    public function testSeventyMinusFivePlusTwentyFiveIsNinety(): void
    {
        $part = self::part(d: 70, pre: 50, knownR: 30);
        $result = self::aggregate(self::targets([self::group([$part])]), self::training());
        self::assertNull($result->unavailableReason);
        self::assertSame('90', $result->quantities?->expectedNetNumerator);
        self::assertSame('1', $result->quantities->expectedNetDenominator);
        self::assertSame('5', $result->quantities->futureReturnsNumerator);
        self::assertSame('25', $result->quantities->retainedOpenNumerator);
        self::assertSame('70', $result->quantities->currentD);
        $output = self::first($result);
        self::assertSame($part->trace, $output->trace);
        self::assertSame('RUB', $output->trace->currency);
        self::assertSame('123456', $output->trace->amountMinor);
        self::assertSame('80', $output->delivered->probabilityNumerator);
        self::assertSame(1300, $output->delivered->attempts[0]['sampleQuantity']);
        self::assertSame('1120', $output->delivered->probabilityDenominator);
        self::assertSame('1250', $output->openPre->probabilityNumerator);
        self::assertSame('260', $output->openPre->cumulativeReturnNumerator);
        self::assertSame(30, $result->skus[0]->days[0]->groups[0]->returns[0]->quantity);
    }

    public function testUnknownOrMissingPositiveComponentMakesEveryFullParentNull(): void
    {
        foreach ([
            [self::part(id: 'z', unknown: 1), self::training(), 'unknown_outcome'],
            [self::part(id: 'z', d: null), self::training(), 'unknown_outcome'],
            [self::part(id: 'z', reason: 'ambiguous_delivery_allocation'), self::training(), 'ambiguous_delivery_allocation'],
            [self::part(id: 'z', reason: 'contradictory_quantity'), self::training(), 'contradictory_quantity'],
            [self::part(id: 'z', reason: 'postings_coverage_missing'), self::training(), 'postings_coverage_missing'],
            [self::part(id: 'z', complete: false), self::training(), 'target_input_incomplete'],
            [self::part(id: 'z'), self::training(returns: []), 'return_risk_unavailable'],
            [self::part(id: 'z', d: 0, pre: 1), self::training(gross: []), 'open_retention_unavailable'],
        ] as [$bad, $training, $reason]) {
            $good = self::part(id: 'a', d: 0);
            $result = self::aggregate(self::targets([self::group([$good]), self::group([$bad], order: 'order-z')]), $training);
            self::assertSame($reason, $result->unavailableReason);
            self::assertSame($reason, $result->moneyUnavailableReason);
            self::assertNull($result->expectedNetRevenue);
            self::assertNull($result->quantities);
            self::assertNull($result->skus[0]->quantities);
            self::assertNull($result->skus[0]->days[0]->quantities);
            self::assertNull($result->skus[0]->days[0]->groups[1]->quantities);
            self::assertNull($result->skus[0]->days[0]->groups[1]->parts[0]->quantities);
            self::assertSame('0', self::first($result)->quantities?->expectedNetNumerator);
        }
    }

    public function testSourceReasonDoesNotEraseValidSiblingsOrTurnZeroUnknownIntoAvailability(): void
    {
        $result = self::aggregate(self::targets([
            self::group([self::part(id: 'a', d: 0)]),
            self::group([self::part(id: 'z', reason: 'delivery_observation_unknown')], order: 'order-z'),
        ], reason: 'delivery_observation_unknown'), self::training());
        self::assertCount(2, $result->skus[0]->days[0]->groups);
        self::assertNotNull(self::first($result)->quantities);
        self::assertNull($result->quantities);
        self::assertSame('delivery_observation_unknown', $result->unavailableReason);
    }

    public function testCompleteTerminalZeroAndProvedEmptyDayDoNotRequireTraining(): void
    {
        $context = self::context();
        $noTraining = new NetTrainingBatch(self::COMPANY, self::ACCOUNT, $context->readAt, null, [], [], 'private_training_failure');
        $zero = self::aggregate(self::targets([self::group([self::part(d: 0, origin: false)])]), $noTraining);
        self::assertSame('0', $zero->quantities?->expectedNetNumerator);
        self::assertSame([], self::first($zero)->delivered->attempts);
        self::assertSame('no_remaining_delivery', self::first($zero)->delivered->basis);
        $emptyDay = new NetTargetBatch($context, [new NetTargetSkuRow(self::SKU, [new NetTargetDayRow(self::DAY, [], true, null)])]);
        self::assertSame('0', self::aggregate($emptyDay, $noTraining)->quantities?->expectedNetNumerator);
        $missingDay = new NetTargetBatch($context, [new NetTargetSkuRow(self::SKU, [new NetTargetDayRow(self::DAY, [], false, null)])]);
        self::assertSame('source_history_unavailable', self::aggregate($missingDay, $noTraining)->unavailableReason);
    }

    public function testGenuineGrossZeroNeedsNoReturnCurveButMissingGrossIsNotZero(): void
    {
        $training = self::training(gross: [self::gross(gross: 0, t2: 2000)], returns: []);
        $result = self::aggregate(self::targets([self::group([self::part(d: 0, pre: 50)])]), $training);
        self::assertSame('0', $result->quantities?->expectedNetNumerator);
        self::assertSame('0', self::first($result)->openPre->probabilityNumerator);
        self::assertNull(self::first($result)->openPre->cumulativeReturnNumerator);
        self::assertCount(1, self::first($result)->openPre->attempts);
        self::assertNull(self::aggregate(self::targets([self::group([self::part(d: 0, pre: 50)])]), self::training(gross: [], returns: []))->quantities);
    }

    public function testCoefficientReuseKeepsActualQuantitiesAndTargetGuards(): void
    {
        $context = self::context();
        $parts = [self::part(id: 'a', d: 14), self::part(id: 'b', d: 28, origin: $context->readAt->modify('-20 days -1 hour'))];
        $result = self::aggregate(self::targets([self::group([$parts[0]]), self::group([$parts[1]], order: 'real-order-b')]), self::training());
        $a = self::first($result);
        $b = $result->skus[0]->days[0]->groups[1]->parts[0];
        self::assertSame($a->delivered, $b->delivered);
        self::assertSame('1', $a->quantities?->futureReturnsNumerator);
        self::assertSame('2', $b->quantities?->futureReturnsNumerator);
        self::assertSame('real-order-b', $result->skus[0]->days[0]->groups[1]->orderNumber);
        foreach ([
            [self::part(id: 'z', origin: false), 'delivery_observation_unknown'],
            [self::part(id: 'z', origin: $context->readAt->modify('+1 microsecond')), 'delivery_observation_in_future'],
            [self::part(id: 'z', origin: new \DateTimeImmutable('2025-12-31T23:59:59Z')), 'source_history_unavailable'],
            [self::part(id: 'z', d: -1), 'contradictory_quantity'],
        ] as [$bad, $reason]) {
            $result = self::aggregate(self::targets([self::group([$parts[0], $bad])]), self::training());
            self::assertSame($reason, $result->unavailableReason);
            self::assertSame($reason, $result->moneyUnavailableReason);
            self::assertNull($result->expectedNetRevenue);
            self::assertSame($reason, $result->skus[0]->days[0]->groups[0]->parts[1]->delivered->unavailableReason);
        }
    }

    public function testAgeBinsRespectMicrosecondsAndHorizonCannotReuseFreshCoefficient(): void
    {
        $context = self::context();
        foreach ([['-1 day +1 microsecond', 0], ['-1 day', 1], ['-60 days +1 microsecond', 59]] as [$offset, $age]) {
            $result = self::aggregate(self::targets([self::group([self::part(origin: $context->readAt->modify($offset))])]), self::training());
            self::assertNotNull($result->quantities);
            self::assertSame($age, self::first($result)->delivered->ageDays);
        }
        $result = self::aggregate(self::targets([self::group([
            self::part(id: 'a', origin: $context->readAt->modify('-60 days +1 microsecond')),
            self::part(id: 'b', origin: $context->readAt->modify('-60 days')),
        ])]), self::training());
        self::assertSame('target_horizon_not_ready', $result->unavailableReason);
        self::assertNotNull(self::first($result)->quantities);
        self::assertNull($result->skus[0]->days[0]->groups[0]->parts[1]->quantities);
    }

    public function testOpenStagesHaveSeparateCoefficientsAndReuseActualQuantities(): void
    {
        $training = self::training(gross: [self::gross(gross: 1000, t1: 1000, t2: 0)]);
        $result = self::aggregate(self::targets([self::group([
            self::part(id: 'a', d: 0, pre: 10, post: 20),
            self::part(id: 'b', d: 0, pre: 30, post: 40),
        ])]), $training);
        $a = self::first($result);
        $b = $result->skus[0]->days[0]->groups[0]->parts[1];
        self::assertSame($a->openPre, $b->openPre);
        self::assertSame($a->openPost, $b->openPost);
        self::assertNotSame($a->openPre, $a->openPost);
        self::assertSame('2000', $a->openPre->probabilityDenominator);
        self::assertSame('1000', $a->openPost->probabilityDenominator);
        self::assertSame('20', $a->quantities?->retainedOpenNumerator);
        self::assertSame('44', $b->quantities?->retainedOpenNumerator);
    }

    public function testFallbackPairRemainsOneScopeAndCompactTraceIsRetained(): void
    {
        $training = self::training(gross: [self::gross(gross: 1800, t2: 200), self::gross(sku: null)], returns: [self::returns(reason: 'returns_coverage_missing'), self::returns(sku: null)]);
        $result = self::aggregate(self::targets([self::group([self::part(d: 0, pre: 50)])]), $training);
        $open = self::first($result)->openPre;
        self::assertSame('25', $result->quantities?->expectedNetNumerator);
        self::assertSame('account', $open->basis);
        self::assertSame(['sku', 'account'], array_column($open->attempts, 'scope'));
        self::assertSame('returns_coverage_missing', $open->attempts[0]['reason']);
        self::assertSame(2000, $open->attempts[1]['sampleQuantity']);
        $encoded = json_encode($result, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('ageProfile', $encoded);
        self::assertStringNotContainsString('returnCurve', $encoded);
        self::assertStringNotContainsString('grossRate', $encoded);
        self::assertStringContainsString('123456', $encoded);
    }

    public function testSuccessiveCallsNeverShareCoefficientsAcrossCompanies(): void
    {
        $action = self::action();
        $targets = self::targets([self::group([self::part(d: 0, pre: 50)])]);
        self::assertSame('25', $action(self::COMPANY, self::ACCOUNT, $targets, self::training())->quantities?->expectedNetNumerator);
        $foreign = self::context(company: self::FOREIGN);
        $training = self::training(gross: [self::gross(gross: 0, t2: 2000)], context: $foreign);
        $targets = self::targets([self::group([self::part(d: 0, pre: 50)])], context: $foreign);
        self::assertSame('0', $action(self::FOREIGN, self::ACCOUNT, $targets, $training)->quantities?->expectedNetNumerator);
    }

    public function testReadinessTranslationPreservesItsOwnIdentityBoundsAndFlags(): void
    {
        foreach ([
            [self::proof(), null],
            [self::proof(order: 'other'), 'target_readiness_mismatch'],
            [self::proof(sku: 'other'), 'target_readiness_mismatch'],
            [self::proof(age: 68), 'target_readiness_mismatch'],
            [self::proof(policy: 'other'), 'unsupported_target_coverage_policy'],
            [self::proof(history: false), 'source_history_unavailable'],
            [self::proof(postings: false), 'postings_coverage_missing'],
            [self::proof(returns: false), 'returns_coverage_missing'],
            [self::proof(from: 1), 'returns_coverage_missing'],
            [self::proof(through: 66), 'returns_coverage_missing'],
            [self::proof(deep: 66), 'returns_coverage_missing'],
            [self::proof(deep: null), 'returns_coverage_missing'],
            [self::proof(through: 68), 'target_evidence_in_future'],
            [self::proof(deep: 68), 'target_evidence_in_future'],
        ] as [$proof, $reason]) {
            $context = self::context();
            $part = self::part(origin: $context->readAt->modify('-67 days'), readiness: $proof);
            $noTraining = new NetTrainingBatch(self::COMPANY, self::ACCOUNT, $context->readAt, null, [], []);
            $result = self::aggregate(self::targets([self::group([$part])]), $noTraining);
            self::assertSame($reason, $result->unavailableReason);
            self::assertSame($reason, $result->moneyUnavailableReason);
            if (null !== $reason) {
                self::assertNull($result->expectedNetRevenue);
            }
            if (null === $reason) {
                self::assertSame('70', $result->quantities?->expectedNetNumerator);
                self::assertSame('mature_target', self::first($result)->delivered->basis);
            } else {
                self::assertNull($result->quantities);
            }
        }
    }

    public function testAgedTargetsAlwaysUseTheirOwnProofAndDoNotMemoizeAvailability(): void
    {
        $origin = self::context()->readAt->modify('-67 days');
        foreach ([self::proof(returns: false), null] as $proof) {
            $result = self::aggregate(self::targets([self::group([
                self::part(id: 'a', origin: $origin, readiness: self::proof()),
                self::part(id: 'b', origin: $origin, readiness: $proof),
            ])]), self::training());
            self::assertSame('70', self::first($result)->quantities?->expectedNetNumerator);
            self::assertSame(null === $proof ? 'target_readiness_missing' : 'returns_coverage_missing', $result->unavailableReason);
        }
    }

    public function testForeignScopeBeforeZeroAndAllUnavailableShortcuts(): void
    {
        $context = self::context();
        $badProof = self::part(id: 'z', d: 0, readiness: self::proof(context: self::context(company: self::FOREIGN)));
        foreach ([
            [self::targets([], context: self::context(company: self::FOREIGN)), self::training()],
            [self::targets([], context: self::context(account: self::FOREIGN)), self::training()],
            [self::targets([]), self::training(context: self::context(company: self::FOREIGN))],
            [self::targets([]), new NetTrainingBatch(self::FOREIGN, self::ACCOUNT, $context->readAt, null, [], [])],
            [self::targets([]), new NetTrainingBatch(self::COMPANY, self::FOREIGN, $context->readAt, null, [], [])],
            [self::targets([self::group([self::part(id: 'a', reason: 'unknown_outcome'), $badProof])], reason: 'input_budget_exceeded'), self::training()],
        ] as [$targets, $training]) {
            try {
                self::aggregate($targets, $training);
                self::fail('Foreign scope produced metadata.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSnapshotContextReadAtAndEpochMicrosecondsPrecedeSourceReasons(): void
    {
        foreach ([null, '', 'single_statement'] as $snapshot) {
            $targets = self::targets([self::group([self::part(d: 0)])], context: self::context(snapshot: $snapshot), reason: 'unknown_outcome');
            self::assertSame('target_snapshot_missing', self::aggregate($targets, self::training())->unavailableReason);
        }
        foreach ([self::context(snapshot: 'other'), self::context(start: '2026-01-01T00:00:00.000001Z'), self::context(end: '2026-11-01T00:00:00.000001Z')] as $context) {
            $result = self::aggregate(self::targets([self::group([self::part(d: 0)])], reason: 'unknown_outcome'), self::training(context: $context));
            self::assertSame('training_context_mismatch', $result->unavailableReason);
            self::assertSame('training_context_mismatch', $result->moneyUnavailableReason);
            self::assertNull($result->expectedNetRevenue);
            self::assertSame([], $result->skus);
        }
        $context = self::context();
        $badTime = new NetTrainingBatch(self::COMPANY, self::ACCOUNT, $context->readAt->modify('+1 microsecond'), null, [], []);
        self::assertSame('training_context_mismatch', self::aggregate(self::targets([self::group([self::part(d: 0)])]), $badTime)->unavailableReason);
        foreach (['2026-01-01T00:00:00.000001Z', '2025-12-31T23:59:59.999999Z'] as $start) {
            $proof = self::proof(context: self::context(start: $start));
            $part = self::part(d: 0, readiness: $proof);
            self::assertSame('training_context_mismatch', self::aggregate(self::targets([self::group([$part])]), self::training())->unavailableReason);
        }
        foreach (['2026-11-01T00:00:00.000001Z', '2026-10-31T23:59:59.999999Z'] as $end) {
            $proof = self::proof(context: self::context(end: $end));
            self::assertSame('training_context_mismatch', self::aggregate(self::targets([self::group([self::part(d: 0, readiness: $proof)])]), self::training())->unavailableReason);
        }
    }

    public function testEmptyRequestHasNoContextWhileSourceAbsenceIsNotZero(): void
    {
        $context = self::context();
        $noTraining = new NetTrainingBatch(self::COMPANY, self::ACCOUNT, $context->readAt, null, [], []);
        $empty = self::aggregate(self::targets([]), $noTraining);
        self::assertSame([], $empty->skus);
        self::assertSame('0', $empty->quantities?->expectedNetNumerator);
        self::assertNull($empty->readAt);
        self::assertNull($empty->context);
        self::assertNull($empty->expectedNetRevenue);
        self::assertSame('money_currency_missing', $empty->moneyUnavailableReason);
        $missing = self::aggregate(self::targets([], reason: 'source_history_unavailable'), $noTraining);
        self::assertNull($missing->quantities);
        self::assertSame('source_history_unavailable', $missing->unavailableReason);
    }

    public function testInputNestedAndOutputBudgetsRefuseWholeAffectedResult(): void
    {
        $targets = self::targets([self::group([self::part()])]);
        foreach ([new NetTargetLimits(outputBytes: 128), new NetTargetLimits(sourceKeys: 1), new NetTargetLimits(sourcesPerGroup: 1)] as $limits) {
            $target = 128 === $limits->outputBytes ? $targets : self::targets([self::group([self::part(id: 'a'), self::part(id: 'b')])]);
            $result = self::action($limits)(self::COMPANY, self::ACCOUNT, $target, self::training());
            self::assertSame('input_budget_exceeded', $result->unavailableReason);
            self::assertSame('input_budget_exceeded', $result->moneyUnavailableReason);
            self::assertNull($result->expectedNetRevenue);
            self::assertNull($result->quantities);
            self::assertSame([], $result->skus);
        }
        $budget = new NetTargetBatch(self::context(), $targets->skus, 'unknown_outcome', new NetTrainingBudgetFailure('parts', 2, 1));
        self::assertSame('input_budget_exceeded', self::aggregate($budget, self::training())->unavailableReason);
        $query = new NetTargetBatch(self::context(), $targets->skus, 'query_budget_exceeded');
        self::assertSame('query_budget_exceeded', self::aggregate($query, self::training())->unavailableReason);
    }

    public function testRuntimeBudgetsRefuseEveryPartialHierarchy(): void
    {
        $targets = self::targets([self::group([self::part(d: 70, pre: 50)])]);
        foreach ([new NetTargetLimits(phpPeakBytes: 1), new NetTargetLimits(phpAllocationBytes: 1), new NetTargetLimits(computeSeconds: 0.000000001)] as $limits) {
            $result = self::action($limits)(self::COMPANY, self::ACCOUNT, $targets, self::training());
            self::assertSame('input_budget_exceeded', $result->unavailableReason);
            self::assertSame('input_budget_exceeded', $result->moneyUnavailableReason);
            self::assertNull($result->expectedNetRevenue);
            self::assertNull($result->quantities);
            self::assertSame([], $result->skus);
        }
        self::assertSame('90', self::aggregate($targets, self::training())->quantities?->expectedNetNumerator);
    }

    public function testSharedComputeStartCannotResetAfterMapping(): void
    {
        $targets = self::targets([self::group([self::part()])]);
        $result = self::action()(self::COMPANY, self::ACCOUNT, $targets, self::training(), hrtime(true) - 6000000000, memory_get_usage(true));
        self::assertSame('input_budget_exceeded', $result->unavailableReason);
        self::assertSame('input_budget_exceeded', $result->moneyUnavailableReason);
        self::assertNull($result->expectedNetRevenue);
        self::assertNull($result->quantities);
        self::assertSame([], $result->skus);
    }

    public function testRepresentativeTwoThousandGroupsAndFullCalendarFitOutputBudget(): void
    {
        $context = self::context();
        $skus = [];
        for ($sku = 0; $sku < 50; ++$sku) {
            $name = 'SKU-'.$sku;
            $days = [];
            for ($day = 0; $day < 184; ++$day) {
                $date = $context->readAt->modify('-'.(183 - $day).' days')->format('Y-m-d');
                $groups = [];
                if (0 === $day) {
                    for ($group = 0; $group < 40; ++$group) {
                        $id = $name.'-'.$group;
                        $groups[] = new NetTargetGroupRow('order-'.$id, $name, $date, [self::part(id: $id, d: 70, pre: 50)], [], true, null);
                    }
                }
                $days[] = new NetTargetDayRow($date, $groups, true, null);
            }
            $skus[] = new NetTargetSkuRow($name, $days);
        }
        $training = self::training(gross: [self::gross(null)], returns: [self::returns(null)]);
        $baseline = memory_get_usage(true);
        $started = hrtime(true);
        $seriesRequest = new NetForecastSeriesRequest(array_column($skus, 'marketplaceSku'), $context->readAt->modify('-30 days'), $context->readAt, $context->readAt->modify('-183 days'));
        $result = (self::action())(self::COMPANY, self::ACCOUNT, new NetTargetBatch($context, $skus), $training, seriesRequest: $seriesRequest);
        $elapsed = hrtime(true) - $started;
        $bytes = \strlen(json_encode($result, \JSON_THROW_ON_ERROR));
        fwrite(\STDERR, \sprintf("B12 representative compute_ns=%d peak_bytes=%d allocation_bytes=%d compact_output_bytes=%d reason=%s\n", $elapsed, memory_get_peak_usage(true), memory_get_usage(true) - $baseline, $bytes, $result->unavailableReason ?? 'available'));
        self::assertNotNull($result->series);
        self::assertCount(50, $result->series->skus);
        self::assertCount(31, $result->series->skus[0]->points);
        self::assertSame('3600.000', $result->series->skus[0]->points[30]->cumulative->quantities?->expectedNetDecimal());
        self::assertSame(22222080000, $result->expectedNetRevenue?->minorAmount());
        self::assertNull($result->moneyUnavailableReason);
        self::assertLessThanOrEqual(5000000000, $elapsed);
        self::assertLessThanOrEqual(100663296, memory_get_peak_usage(true));
        self::assertLessThanOrEqual(50331648, memory_get_usage(true) - $baseline);
        self::assertNull($result->unavailableReason);
        self::assertSame('180000', $result->quantities?->expectedNetNumerator);
        self::assertSame('1', $result->quantities->expectedNetDenominator);
        self::assertLessThanOrEqual(33554432, \strlen(json_encode($result, \JSON_THROW_ON_ERROR)));
    }

    public function testSeriesAndOriginalSkuBatchTotalsKeepAllExactFieldsAndReasonPriority(): void
    {
        foreach ([
            [self::part(id: 'a', d: 0, pre: 1, price: '1'), self::part(id: 'b', d: 0, pre: 1, price: '1')],
            [self::part(id: 'a', price: null), self::part(id: 'b')],
            [self::part(id: 'a', currency: 'USD'), self::part(id: 'b')],
            [self::part(id: 'a', d: 14, price: (string) \PHP_INT_MAX), self::part(id: 'b', d: 14, price: (string) -\PHP_INT_MAX)],
            [self::part(id: 'a', reason: 'return_risk_unavailable'), self::part(id: 'b', unknown: 1)],
        ] as $parts) {
            $days = [];
            foreach ($parts as $i => $part) {
                $date = 0 === $i ? '2026-10-08' : '2026-10-09';
                $days[] = new NetTargetDayRow($date, [new NetTargetGroupRow('order-'.$i, self::SKU, $date, [$part], [], true, null)], true, null);
            }
            $targets = new NetTargetBatch(self::context(), [new NetTargetSkuRow(self::SKU, $days)]);
            $original = self::aggregate($targets, self::training());
            $request = new NetForecastSeriesRequest([self::SKU], new \DateTimeImmutable('2026-10-09'), new \DateTimeImmutable('2026-10-09'), new \DateTimeImmutable('2026-10-08'));
            $series = (self::action())(self::COMPANY, self::ACCOUNT, $targets, self::training(), seriesRequest: $request);
            self::assertNotNull($series->series);
            foreach (['quantities', 'expectedNetRevenue', 'unavailableReason', 'moneyUnavailableReason'] as $field) {
                self::assertEquals($original->$field, $series->$field, 'batch '.$field);
                self::assertEquals($original->skus[0]->$field, $series->skus[0]->$field, 'SKU '.$field);
                self::assertEquals($original->skus[0]->$field, $series->series->skus[0]->points[0]->cumulative->$field, 'final cumulative '.$field);
            }
            self::assertCount(2, $series->skus[0]->days);
            self::assertCount(1, $series->skus[0]->days[0]->groups);
        }
    }

    public function testOptionalSeriesCannotBypassScopeContextCompletenessOrSharedBudgets(): void
    {
        $date = new \DateTimeImmutable(self::DAY);
        $request = new NetForecastSeriesRequest([self::SKU], $date, $date);
        $targets = self::targets([self::group([self::part(d: 0)])]);
        foreach ([self::context(snapshot: 'different'), self::context(start: '2026-01-01T00:00:00.000001Z'), self::context(end: '2026-11-01T00:00:00.000001Z')] as $context) {
            $result = (self::action())(self::COMPANY, self::ACCOUNT, $targets, self::training(context: $context), seriesRequest: $request);
            self::assertSame('training_context_mismatch', $result->unavailableReason);
            self::assertNull($result->series);
        }
        $context = self::context();
        $late = new NetTrainingBatch(self::COMPANY, self::ACCOUNT, $context->readAt->modify('+1 microsecond'), null, [], []);
        self::assertSame('training_context_mismatch', (self::action())(self::COMPANY, self::ACCOUNT, $targets, $late, seriesRequest: $request)->unavailableReason);
        foreach ([self::context(company: self::FOREIGN), self::context(account: self::FOREIGN)] as $foreign) {
            try {
                (self::action())(self::COMPANY, self::ACCOUNT, self::targets([], context: $foreign), self::training(), seriesRequest: new NetForecastSeriesRequest([], $date, $date));
                self::fail('Series empty shortcut accepted foreign scope.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        foreach ([new NetTargetLimits(phpPeakBytes: 1), new NetTargetLimits(phpAllocationBytes: 1), new NetTargetLimits(computeSeconds: 0.000000001)] as $limits) {
            $result = (self::action())(self::COMPANY, self::ACCOUNT, $targets, self::training(), runtimeLimits: $limits, seriesRequest: $request);
            self::assertSame('input_budget_exceeded', $result->unavailableReason);
            self::assertNull($result->series);
            self::assertSame([], $result->skus);
        }
        $expired = (self::action())(self::COMPANY, self::ACCOUNT, $targets, self::training(), computeStarted: hrtime(true) - 6000000000, seriesRequest: $request);
        self::assertSame('input_budget_exceeded', $expired->unavailableReason);
        foreach ([new NetForecastSeriesRequest(['missing'], $date, $date), new NetForecastSeriesRequest([self::SKU], $date, $date->modify('+1 day')), new NetForecastSeriesRequest([self::SKU], $date, $date, $date->modify('-1 day'))] as $missing) {
            $result = (self::action())(self::COMPANY, self::ACCOUNT, $targets, self::training(), seriesRequest: $missing);
            self::assertSame('source_history_unavailable', $result->unavailableReason);
            self::assertSame([], $result->skus);
            self::assertNull($result->series);
        }
        $empty = (self::action())(self::COMPANY, self::ACCOUNT, self::targets([]), self::training(), seriesRequest: $request);
        self::assertSame('source_history_unavailable', $empty->unavailableReason);
        self::assertNull($empty->series);
    }

    public function testSeriesQualityUnionsDiagnosticsWithoutSummingTrainingSamples(): void
    {
        $training = self::training(gross: [self::gross(gross: 1800, t2: 200), self::gross(sku: null)], returns: [self::returns(reason: 'returns_coverage_missing'), self::returns(sku: null)]);
        $targets = self::targets([self::group([self::part(id: 'a', d: 0, pre: 50), self::part(id: 'b', d: 0, pre: 50)])]);
        $date = new \DateTimeImmutable(self::DAY);
        $result = (self::action())(self::COMPANY, self::ACCOUNT, $targets, $training, seriesRequest: new NetForecastSeriesRequest([self::SKU], $date, $date));
        self::assertNotNull($result->series);
        $scope = $result->series->skus[0]->points[0]->period;
        self::assertSame(['account', 'no_eligible_open', 'no_remaining_delivery'], $scope->basis);
        self::assertSame(['returns_coverage_missing'], $scope->reasons);
        self::assertSame('50.000', $scope->quantities?->expectedNetDecimal());
        $json = json_encode($result->series, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('sampleQuantity', $json);
        self::assertStringNotContainsString('sourceRowId', $json);
        self::assertStringNotContainsString('rawDocumentId', $json);
    }

    public function testOptionalSeriesKeepsFirstMonetaryFailureBeforeLaterPeriodFailure(): void
    {
        foreach ([
            [['RUB', '1'], ['USD', '1'], ['RUB', null], 'money_currency_mismatch', 'money_price_missing'],
            [['RUB', '1'], ['USD', null], ['USD', '1'], 'money_price_missing', 'money_price_missing'],
            [['RUB', null], ['USD', '1'], ['RUB', '1'], 'money_price_missing', 'money_currency_mismatch'],
            [['RUB', '0'], ['USD', '0'], ['RUB', null], 'money_currency_mismatch', 'money_price_missing'],
            [['RUB', (string) \PHP_INT_MAX], ['USD', '1'], ['RUB', null], 'money_currency_mismatch', 'money_price_missing'],
            [['RUB', '1'], ['USD', '1'], [null, '1'], 'money_currency_mismatch', 'money_currency_missing'],
            [['RUB', '1'], [null, '1'], ['USD', '1'], 'money_currency_missing', 'money_currency_missing'],
            [['RUB', '1'], ['USD', '1'], ['USD', 'invalid'], 'money_currency_mismatch', 'money_input_invalid'],
        ] as [$hidden, $firstVisible, $lastVisible, $cumulativeReason, $periodReason]) {
            $days = [];
            foreach (['2026-08-31' => $hidden, '2026-09-01' => $firstVisible, '2026-09-02' => $lastVisible] as $date => [$currency, $price]) {
                $days[] = new NetTargetDayRow($date, [new NetTargetGroupRow('order-'.$date, self::SKU, $date, [self::part(id: $date, d: 14, currency: $currency, price: $price)], [], true, null)], true, null);
            }
            $targets = new NetTargetBatch(self::context(), [new NetTargetSkuRow(self::SKU, $days)]);
            $original = self::aggregate($targets, self::training());
            $request = new NetForecastSeriesRequest([self::SKU], new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-02'), new \DateTimeImmutable('2026-08-31'), 'month');
            $series = (self::action())(self::COMPANY, self::ACCOUNT, $targets, self::training(), seriesRequest: $request);
            self::assertSame($cumulativeReason, $original->moneyUnavailableReason);
            self::assertSame($original->moneyUnavailableReason, $series->moneyUnavailableReason);
            self::assertSame($original->skus[0]->moneyUnavailableReason, $series->skus[0]->moneyUnavailableReason);
            self::assertNotNull($series->series);
            $point = $series->series->skus[0]->points[0];
            self::assertSame($cumulativeReason, $point->cumulative->moneyUnavailableReason);
            self::assertSame($periodReason, $point->period->moneyUnavailableReason);
            foreach (['quantities', 'expectedNetRevenue', 'unavailableReason'] as $field) {
                self::assertEquals($original->$field, $series->$field);
                self::assertEquals($original->skus[0]->$field, $point->cumulative->$field);
            }
        }
    }

    public function testOptionalSeriesUsesTheSameFullResultAndOutputBudget(): void
    {
        $context = self::context();
        $targets = self::targets([self::group([self::part()])]);
        $date = new \DateTimeImmutable($targets->skus[0]->days[0]->businessDate);
        $request = new NetForecastSeriesRequest([self::SKU], $date, $date);
        $result = (self::action())($context->companyId, $context->accountId, $targets, self::training(), seriesRequest: $request);
        self::assertNotNull($result->series);
        self::assertSame($result->quantities?->expectedNetDecimal(), $result->series->skus[0]->points[0]->period->quantities?->expectedNetDecimal());
        $failed = (self::action())($context->companyId, $context->accountId, $targets, self::training(), runtimeLimits: new NetTargetLimits(outputBytes: 1), seriesRequest: $request);
        self::assertSame('input_budget_exceeded', $failed->unavailableReason);
        self::assertSame([], $failed->skus);
        self::assertNull($failed->series);
        $low = 1;
        $high = 50000;
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            $probe = (self::action())($context->companyId, $context->accountId, $targets, self::training(), runtimeLimits: new NetTargetLimits(outputBytes: $mid));
            if ('input_budget_exceeded' === $probe->unavailableReason) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }
        $withSeries = (self::action())($context->companyId, $context->accountId, $targets, self::training(), runtimeLimits: new NetTargetLimits(outputBytes: $low), seriesRequest: $request);
        self::assertSame('input_budget_exceeded', $withSeries->unavailableReason, 'Series fields must share the original output byte budget.');
        self::assertNull($withSeries->series);
    }

    public function testStableReasonOrderDoesNotDependOnInputOrder(): void
    {
        $a = self::group([self::part(id: 'a', reason: 'unknown_outcome')]);
        $z = self::group([self::part(id: 'z', reason: 'returns_coverage_missing')], order: 'order-z');
        $result = self::aggregate(self::targets([$z, $a], reason: 'returns_coverage_missing'), self::training());
        self::assertSame('unknown_outcome', $result->unavailableReason);
    }

    public function testSourceFailurePrecedesModelUnavailabilityAndMissingContextDoesNotInventRates(): void
    {
        $result = self::aggregate(self::targets([
            self::group([self::part(id: 'a')]),
            self::group([self::part(id: 'z', reason: 'postings_coverage_missing')], order: 'order-z'),
        ]), self::training(returns: []));
        self::assertSame('postings_coverage_missing', $result->unavailableReason);
        self::assertSame('return_risk_unavailable', self::first($result)->delivered->unavailableReason);
        $context = self::context();
        $missing = new NetTrainingBatch(self::COMPANY, self::ACCOUNT, $context->readAt, null, [], []);
        $result = self::aggregate(self::targets([self::group([self::part(d: 1, pre: 1)])]), $missing);
        self::assertNull($result->quantities);
        self::assertSame('training_context_missing', self::first($result)->delivered->attempts[0]['reason']);
        self::assertSame('training_context_missing', self::first($result)->openPre->unavailableReason);
        self::assertSame([], self::first($result)->openPre->attempts);
    }

    public function testAggregationAcrossRowsNeverOverflowsNativeInteger(): void
    {
        $part = self::part(d: \PHP_INT_MAX, origin: self::context()->readAt->modify('-67 days'), readiness: self::proof());
        $other = self::part(id: 'b', d: \PHP_INT_MAX, origin: $part->deliveryOrigin, readiness: self::proof());
        $result = self::aggregate(self::targets([self::group([$part, $other])]), self::training());
        self::assertSame('18446744073709551614', $result->quantities?->expectedNetNumerator);
        self::assertSame('1', $result->quantities->expectedNetDenominator);
    }

    public function testRuntimeOutputBudgetIncludesMoneyAndRefusesWholeResult(): void
    {
        $targets = self::targets([self::group([self::part(d: 0, price: '0')])]);
        $result = self::action()(self::COMPANY, self::ACCOUNT, $targets, self::training(), null, null, new NetTargetLimits(outputBytes: 128));
        self::assertSame('input_budget_exceeded', $result->unavailableReason);
        self::assertSame('input_budget_exceeded', $result->moneyUnavailableReason);
        self::assertNull($result->quantities);
        self::assertNull($result->expectedNetRevenue);
        self::assertSame([], $result->skus);
    }

    public function testTightOutputBudgetChargesMoneyAndPrivateDayCarry(): void
    {
        $context = self::context();
        $empty = new NetTargetBatch($context, [new NetTargetSkuRow(self::SKU, [new NetTargetDayRow(self::DAY, [], true, null)])]);
        $priced = self::targets([self::group([self::part(d: 0, price: '0')])]);
        foreach ([[$empty, 0], [$priced, 0]] as [$targets, $minor]) {
            $low = 1;
            $high = 20000;
            while ($low < $high) {
                $middle = intdiv($low + $high, 2);
                $probe = self::action(new NetTargetLimits(outputBytes: $middle))(self::COMPANY, self::ACCOUNT, $targets, self::training());
                if ('input_budget_exceeded' === $probe->unavailableReason) {
                    $low = $middle + 1;
                } else {
                    $high = $middle;
                }
            }
            $result = self::action(new NetTargetLimits(outputBytes: $low))(self::COMPANY, self::ACCOUNT, $targets, self::training());
            self::assertNull($result->unavailableReason);
            self::assertSame($minor, (int) $result->quantities?->expectedNetNumerator);
            $denied = self::action(new NetTargetLimits(outputBytes: $low - 1))(self::COMPANY, self::ACCOUNT, $targets, self::training());
            self::assertSame('input_budget_exceeded', $denied->unavailableReason);
            self::assertNull($denied->expectedNetRevenue);
            self::assertSame([], $denied->skus);
            // The private day value alone reserves at least 512 bytes beyond public JSON.
            self::assertGreaterThan(512, $low - \strlen(json_encode($result, \JSON_THROW_ON_ERROR)));
        }
    }

    public function testTightAsciiBudgetStillRefusesEscapedValuesWithoutPartialMoney(): void
    {
        $ascii = self::targets([self::group([self::part(id: str_repeat('a', 100), d: 0, price: '0')])]);
        $low = 1;
        $high = 30000;
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            $probe = self::action(new NetTargetLimits(outputBytes: $middle))(self::COMPANY, self::ACCOUNT, $ascii, self::training());
            if ('input_budget_exceeded' === $probe->unavailableReason) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }
        $limits = new NetTargetLimits(outputBytes: $low);
        self::assertSame(0, self::action($limits)(self::COMPANY, self::ACCOUNT, $ascii, self::training())->expectedNetRevenue?->minorAmount());
        foreach (['"', '\\', '/', "\n", '<', '>', '&', "'", 'Ж'] as $unsafe) {
            $id = str_repeat('a', 98).$unsafe;
            if (1 === \strlen($unsafe)) {
                $id .= $unsafe;
            }
            self::assertSame(100, \strlen($id));
            $targets = self::targets([self::group([self::part(id: $id, d: 0, price: '0')])]);
            $result = self::action($limits)(self::COMPANY, self::ACCOUNT, $targets, self::training());
            self::assertSame('input_budget_exceeded', $result->unavailableReason, 'Unsafe suffix must retain conservative JSON escaping.');
            self::assertNull($result->quantities);
            self::assertNull($result->expectedNetRevenue);
            self::assertSame([], $result->skus);
        }
    }

    public function testRevenueUsesSourcePricesAndRemainingUnitsAtEveryLevel(): void
    {
        $a = self::part(id: 'a', d: 14, knownR: 6, price: '100');
        $b = self::part(id: 'b', d: 28, knownR: 2, price: '200');
        $result = self::aggregate(self::targets([self::group([$b, $a])]), self::training());
        self::assertSame('39', $result->quantities?->expectedNetNumerator);
        $sku = $result->skus[0];
        $day = $sku->days[0];
        $group = $day->groups[0];
        foreach ([$result, $sku, $day, $group] as $scope) {
            self::assertSame(6500, $scope->expectedNetRevenue?->minorAmount());
            self::assertSame('RUB', $scope->expectedNetRevenue->currency());
            self::assertNull($scope->moneyUnavailableReason);
        }
        self::assertSame(1300, $group->parts[0]->expectedNetRevenue?->minorAmount());
        self::assertSame(5200, $group->parts[1]->expectedNetRevenue?->minorAmount());
    }

    public function testMoneyFailuresPreserveUnitsAndAvailableSiblingsIncludingZero(): void
    {
        foreach ([[null, 'RUB', 'money_price_missing'], ['1', null, 'money_currency_missing'], ['bad', 'RUB', 'money_input_invalid'], ['0', 'USD', 'money_currency_mismatch']] as [$price, $currency, $reason]) {
            foreach ([0, 14] as $d) {
                $result = self::aggregate(self::targets([self::group([self::part(id: 'a', d: 14, price: '100')]), self::group([self::part(id: 'b', d: $d, price: $price, currency: $currency)], order: 'order-b')]), self::training());
                foreach ([$result, $result->skus[0], $result->skus[0]->days[0]] as $scope) {
                    self::assertNotNull($scope->quantities);
                    self::assertNull($scope->unavailableReason);
                    self::assertNull($scope->expectedNetRevenue);
                    self::assertSame($reason, $scope->moneyUnavailableReason);
                }
                self::assertSame(1300, self::first($result)->expectedNetRevenue?->minorAmount());
            }
        }
        $result = self::aggregate(self::targets([self::group([self::part(unknown: 1, price: null)])]), self::training());
        self::assertNull($result->expectedNetRevenue);
        self::assertSame('unknown_outcome', $result->moneyUnavailableReason);
        self::assertSame('unknown_outcome', self::first($result)->moneyUnavailableReason);
    }

    public function testFractionalDaysAndSkusCarryExactRevenuePrivately(): void
    {
        $context = self::context();
        $makeDay = static function (string $sku, string $date, string $id): NetTargetDayRow {
            return new NetTargetDayRow($date, [new NetTargetGroupRow('order-'.$id, $sku, $date, [self::part(id: $id, d: 0, pre: 1, price: '1')], [], true, null)], true, null);
        };
        $result = self::aggregate(new NetTargetBatch($context, [
            new NetTargetSkuRow(self::SKU, [$makeDay(self::SKU, self::DAY, 'a'), $makeDay(self::SKU, '2026-10-09', 'b')]),
            new NetTargetSkuRow('sku-b', [$makeDay('sku-b', self::DAY, 'c')]),
        ]), self::training(gross: [self::gross(null)], returns: [self::returns(null)]));
        $a = $result->skus[0]->days[0];
        $b = $result->skus[0]->days[1];
        self::assertSame(1, $a->expectedNetRevenue?->minorAmount());
        self::assertSame(1, $b->expectedNetRevenue?->minorAmount());
        self::assertSame(1, $a->exactRevenue()->plus($b->exactRevenue())->toMoney()?->minorAmount());
        self::assertSame(1, $result->skus[0]->expectedNetRevenue?->minorAmount());
        self::assertSame(2, $result->expectedNetRevenue?->minorAmount());
        self::assertArrayNotHasKey('revenue', get_object_vars($a));
        self::assertStringNotContainsString('BigRational', json_encode($a, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('exactRevenue', json_encode($a, \JSON_THROW_ON_ERROR));
    }

    public function testDayNativeOverflowKeepsExactCarryForParentCancellation(): void
    {
        $context = self::context();
        $days = [];
        foreach ([['2026-10-08', 'a', '9223372036854775807'], ['2026-10-09', 'b', '-9223372036854775807']] as [$date, $id, $price]) {
            $days[] = new NetTargetDayRow($date, [new NetTargetGroupRow('order-'.$id, self::SKU, $date, [self::part(id: $id, d: 14, price: $price)], [], true, null)], true, null);
        }
        $result = self::aggregate(new NetTargetBatch($context, [new NetTargetSkuRow(self::SKU, $days)]), self::training());
        self::assertSame(0, $result->expectedNetRevenue?->minorAmount());
        self::assertNull($result->moneyUnavailableReason);
        $a = $result->skus[0]->days[0];
        $b = $result->skus[0]->days[1];
        self::assertNull($a->expectedNetRevenue);
        self::assertSame('money_overflow', $a->moneyUnavailableReason);
        self::assertSame(0, $a->exactRevenue()->plus($b->exactRevenue())->toMoney()?->minorAmount());
    }

    private static function action(?NetTargetLimits $limits = null): AggregateNetForecastAction
    {
        $curves = new BuildReturnCurveAction();
        $pre = new BuildPreHandoverGrossRateAction();
        $post = new BuildPostHandoverGrossRateAction();

        return new AggregateNetForecastAction(new AssessDeliveredRiskAction($curves), new AssessOpenRetentionAction($pre, $post, $curves, new ApplyOpenRetentionAction($pre, $post, $curves)), $limits ?? new NetTargetLimits());
    }

    private static function aggregate(NetTargetBatch $targets, NetTrainingBatch $training): NetForecastBatch
    {
        return self::action()(self::COMPANY, self::ACCOUNT, $targets, $training);
    }

    private static function first(NetForecastBatch $batch): NetForecastPart
    {
        return $batch->skus[0]->days[0]->groups[0]->parts[0];
    }

    private static function context(string $company = self::COMPANY, string $account = self::ACCOUNT, ?string $snapshot = 'rr-b09', string $start = '2026-01-01T00:00:00Z', string $end = '2026-11-01T00:00:00Z'): NetTrainingContext
    {
        $epoch = new NetForecastCaptureEpoch('01900000-0000-7000-8000-000000000004', NetForecastCaptureEpoch::CAPTURE_VERSION, new \DateTimeImmutable($start), new \DateTimeImmutable($end), 'release-b09');

        return new NetTrainingContext($company, $account, new \DateTimeImmutable('2026-10-28T12:00:00.123456Z'), $epoch, $snapshot);
    }

    /** @param list<NetTargetGroupRow> $groups */
    private static function targets(array $groups, ?NetTrainingContext $context = null, ?string $reason = null): NetTargetBatch
    {
        return new NetTargetBatch($context ?? self::context(), [] === $groups ? [] : [new NetTargetSkuRow(self::SKU, [new NetTargetDayRow(self::DAY, $groups, true, null)])], $reason);
    }

    /** @param list<NetTargetPartRow> $parts */
    private static function group(array $parts, string $order = 'order-a'): NetTargetGroupRow
    {
        $return = new NetTargetReturnTrace('return-real-1', 'return-rev-1', 'return-rev-2', 2, self::context()->readAt, 'return-raw-1', 'hash', 'ClientReturn', 30);

        return new NetTargetGroupRow($order, self::SKU, self::DAY, $parts, [$return], true, null);
    }

    private static function part(string $id = 'a', ?int $d = 70, int $pre = 0, int $post = 0, int $knownR = 0, int $unknown = 0, \DateTimeImmutable|false|null $origin = null, ?NetTargetReadinessRow $readiness = null, ?string $reason = null, bool $complete = true, ?string $price = '123456', ?string $currency = 'RUB'): NetTargetPartRow
    {
        $t1 = 0 === $d && 0 === $pre && 0 === $post && 0 === $knownR && 0 === $unknown ? 1 : 0;
        $raw = max(0, $d ?? 0) + $pre + $post + $knownR + $unknown + $t1;
        $trace = new NetTargetSourceTrace($id, 'rev-first-'.$id, 'rev-last-'.$id, 2, self::context()->readAt->modify('-90 days'), self::context()->readAt, 'raw-'.$id, 'hash', $price, $currency, [['postingNumber' => 'real-posting-'.$id, 'rawDocumentId' => 'raw-status-'.$id]], ['receipt-1'], 'digest');

        return new NetTargetPartRow($id, 'real-posting-'.$id, $raw, $d, $knownR, $t1, 0, 0, $pre, $post, $unknown, false === $origin ? null : ($origin ?? self::context()->readAt->modify('-20 days')), $readiness, $trace, $complete, $reason);
    }

    private static function proof(int $age = 67, string $order = 'order-a', string $sku = self::SKU, string $policy = NetTrainingContext::COVERAGE_POLICY, int $from = 0, int $through = 67, ?int $deep = 67, bool $history = true, bool $postings = true, bool $returns = true, ?NetTrainingContext $context = null): NetTargetReadinessRow
    {
        $context ??= self::context();
        $origin = $context->readAt->modify('-'.$age.' days');

        return new NetTargetReadinessRow($context, $order, $sku, $origin, $policy, $origin->modify('+'.$from.' days'), $origin->modify('+'.$through.' days'), null === $deep ? null : $origin->modify('+'.$deep.' days'), $history, $postings, $returns);
    }

    /**
     * @param list<GrossTrainingAggregateRow>|null  $gross
     * @param list<ReturnTrainingAggregateRow>|null $returns
     */
    private static function training(?array $gross = null, ?array $returns = null, ?NetTrainingContext $context = null): NetTrainingBatch
    {
        $context ??= self::context();

        return new NetTrainingBatch($context->companyId, $context->accountId, $context->readAt, $context, $gross ?? [self::gross()], $returns ?? [self::returns()]);
    }

    private static function gross(?string $sku = self::SKU, int $gross = 1250, int $t1 = 0, int $t2 = 750): GrossTrainingAggregateRow
    {
        return new GrossTrainingAggregateRow($sku, true, null, 2000, 30, $gross, $t1, $t2, 0, 30, 86400, 0, 0);
    }

    private static function returns(?string $sku = self::SKU, ?string $reason = null): ReturnTrainingAggregateRow
    {
        $profile = [];
        for ($age = 0; $age <= 60; ++$age) {
            $returned = $age < 20 ? 0 : (60 === $age ? 260 : 180);
            $profile[] = new ReturnTrainingAgeRow($age, $returned, 1300 - $returned, 30);
        }

        return new ReturnTrainingAggregateRow($sku, true, $reason, 1300, 30, $profile, 0);
    }
}
