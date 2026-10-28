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
            self::assertNull($result->quantities);
            self::assertSame([], $result->skus);
        }
        $budget = new NetTargetBatch(self::context(), $targets->skus, 'unknown_outcome', new NetTrainingBudgetFailure('parts', 2, 1));
        self::assertSame('input_budget_exceeded', self::aggregate($budget, self::training())->unavailableReason);
        $query = new NetTargetBatch(self::context(), $targets->skus, 'query_budget_exceeded');
        self::assertSame('query_budget_exceeded', self::aggregate($query, self::training())->unavailableReason);
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

    private static function part(string $id = 'a', ?int $d = 70, int $pre = 0, int $post = 0, int $knownR = 0, int $unknown = 0, \DateTimeImmutable|false|null $origin = null, ?NetTargetReadinessRow $readiness = null, ?string $reason = null, bool $complete = true): NetTargetPartRow
    {
        $t1 = 0 === $d && 0 === $pre && 0 === $post && 0 === $knownR && 0 === $unknown ? 1 : 0;
        $raw = max(0, $d ?? 0) + $pre + $post + $knownR + $unknown + $t1;
        $trace = new NetTargetSourceTrace($id, 'rev-first-'.$id, 'rev-last-'.$id, 2, self::context()->readAt->modify('-90 days'), self::context()->readAt, 'raw-'.$id, 'hash', '123456', 'RUB', [['postingNumber' => 'real-posting-'.$id, 'rawDocumentId' => 'raw-status-'.$id]], ['receipt-1'], 'digest');

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
