<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Application\NetForecast\ApplyOpenRetentionAction;
use App\Ingestion\Application\NetForecast\AssessOpenRetentionAction;
use App\Ingestion\Application\NetForecast\BuildPostHandoverGrossRateAction;
use App\Ingestion\Application\NetForecast\BuildPreHandoverGrossRateAction;
use App\Ingestion\Application\NetForecast\BuildReturnCurveAction;
use App\Ingestion\Application\NetForecast\NetTrainingBatch;
use App\Ingestion\Application\NetForecast\OpenRetentionAssessment;
use App\Ingestion\Application\NetForecast\OpenRetentionAttempt;
use App\Ingestion\Application\NetForecast\OpenRetentionResolution;
use App\Ingestion\Application\NetForecast\OpenTargetEvidence;
use App\Ingestion\Application\NetForecast\ScopedPreHandoverGrossRate;
use App\Ingestion\Application\NetForecast\ScopedReturnCurve;
use App\Ingestion\Domain\ValueObject\NetForecastCaptureEpoch;
use App\Ingestion\Infrastructure\Query\NetForecast\GrossTrainingAggregateRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingBudgetFailure;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;
use App\Ingestion\Infrastructure\Query\NetForecast\ReturnTrainingAgeRow;
use App\Ingestion\Infrastructure\Query\NetForecast\ReturnTrainingAggregateRow;
use PHPUnit\Framework\TestCase;

final class AssessOpenRetentionActionTest extends TestCase
{
    private const string COMPANY = '01900000-0000-7000-8000-000000000001';
    private const string ACCOUNT = '01900000-0000-7000-8000-000000000002';
    private const string FOREIGN = '01900000-0000-7000-8000-000000000003';
    private const string SKU = '00123';

    public function testUnavailableSkuReturnFallsBackToWholeAccountPairAtBothStages(): void
    {
        foreach ([false, true] as $post) {
            $skuGross = self::gross(gross: 160, t2: 40);
            $skuReturn = self::returns(reason: 'returns_coverage_missing');
            $accountGross = self::gross(sku: null);
            $accountReturn = self::returns(sku: null);
            $batch = self::batch([$skuGross, $accountGross], [$skuReturn, $accountReturn]);
            $result = self::assess(self::target(post: $post), $batch);
            self::assertNull($result->unavailableReason);
            self::assertCount(2, $result->attempts);
            $first = $result->attempts[0];
            $last = $result->attempts[1];
            self::assertSame(self::SKU, $first->marketplaceSku);
            self::assertNull($last->marketplaceSku);
            self::assertSame('returns_coverage_missing', $first->assessment->unavailableReason);
            self::assertSame($skuGross, $first->assessment->grossRate?->source);
            self::assertSame($skuReturn, $first->assessment->returnCurve?->source);
            self::assertSame($accountGross, $last->assessment->grossRate?->source);
            self::assertSame($accountReturn, $last->assessment->returnCurve?->source);
            self::assertSame($last->assessment, $result->selected);
            self::assertNotNull($result->selected);
            self::assertSame('account', $result->selected->basis);
            self::assertNotNull($result->selected->quantities);
            self::assertSame('50', $result->selected->quantities->expectedGrossNumerator);
            self::assertSame('10', $result->selected->quantities->expectedOpenReturnsNumerator);
            self::assertSame('40', $result->selected->quantities->retainedOpenNumerator);
            self::assertSame('1', $result->selected->quantities->retainedOpenDenominator);
        }
    }

    public function testGoldenSkuPairStopsBeforeAccountAtBothStages(): void
    {
        foreach ([false, true] as $post) {
            $gross = self::gross();
            $returns = self::returns();
            $result = self::assess(self::target(post: $post), self::batch([$gross], [$returns]));
            self::assertCount(1, $result->attempts);
            self::assertSame($result->attempts[0]->assessment, $result->selected);
            self::assertNotNull($result->selected);
            self::assertSame('sku', $result->selected->basis);
            self::assertSame($gross, $result->selected->grossRate?->source);
            self::assertSame($returns, $result->selected->returnCurve?->source);
            self::assertNotNull($result->selected->quantities);
            self::assertSame('50', $result->selected->quantities->expectedGrossNumerator);
            self::assertSame('10', $result->selected->quantities->expectedOpenReturnsNumerator);
            self::assertSame('40', $result->selected->quantities->retainedOpenNumerator);
        }
    }

    public function testUsableHalvesFromDifferentScopesNeverProducePartialNumericResult(): void
    {
        $skuGross = self::gross(reason: 'source_history_unavailable');
        $accountReturn = self::returns(sku: null, reason: 'returns_coverage_missing');
        $result = self::assess(self::target(), self::batch([$skuGross, self::gross(sku: null)], [self::returns(), $accountReturn]));
        self::assertUnavailable('open_retention_unavailable', $result, 2);
        self::assertSame('source_history_unavailable', $result->attempts[0]->assessment->unavailableReason);
        self::assertSame('returns_coverage_missing', $result->attempts[1]->assessment->unavailableReason);
        self::assertNull($result->attempts[0]->assessment->quantities);
        self::assertNull($result->attempts[1]->assessment->quantities);
        self::assertSame($skuGross, $result->attempts[0]->assessment->grossRate?->source);
        self::assertSame($accountReturn, $result->attempts[1]->assessment->returnCurve?->source);
    }

    public function testStageBuilderKeepsT1OnlyInPreHandoverTraining(): void
    {
        $batch = self::batch([self::gross(postings: 60, t1: 100, t2: 0)], [self::returns()]);
        foreach ([[false, '50', '40'], [true, '100', '80']] as [$post, $gross, $retained]) {
            $result = self::assess(self::target(post: $post), $batch);
            self::assertCount(1, $result->attempts);
            self::assertNotNull($result->selected);
            self::assertNotNull($result->selected->quantities);
            self::assertSame($gross, $result->selected->quantities->expectedGrossNumerator);
            self::assertSame($retained, $result->selected->quantities->retainedOpenNumerator);
        }
    }

    public function testTrustedTargetZeroNeedsNoTrainingOrAttemptsAtBothStages(): void
    {
        foreach ([false, true] as $post) {
            foreach ([self::batch([], [], reason: 'private_training_failure'), self::batch([], [], noContext: true)] as $batch) {
                $result = self::assess(self::target(quantity: 0, post: $post), $batch);
                self::assertNull($result->unavailableReason);
                self::assertSame([], $result->attempts);
                self::assertNotNull($result->selected);
                self::assertSame('no_eligible_open', $result->selected->basis);
                self::assertNull($result->selected->grossRate);
                self::assertNull($result->selected->returnCurve);
                self::assertNull($result->selected->grossProbability);
                self::assertNull($result->selected->cumulativeReturnProbability);
                self::assertNotNull($result->selected->quantities);
                self::assertSame(0, $result->selected->quantities->eligibleOpenQuantity);
                self::assertSame('0', $result->selected->quantities->retainedOpenNumerator);
            }
        }
    }

    public function testGenuineGrossZeroKeepsUnknownReturnAndDiagnosticsWithoutAccountFallback(): void
    {
        foreach ([false, true] as $post) {
            foreach ([[], [self::returns(noProfile: true, reason: 'returns_coverage_missing')]] as $returns) {
                $gross = self::gross(gross: 0, t2: 200);
                $result = self::assess(self::target(post: $post), self::batch([$gross], $returns));
                self::assertCount(1, $result->attempts);
                self::assertNotNull($result->selected);
                self::assertSame('sku', $result->selected->basis);
                self::assertSame($gross, $result->selected->grossRate?->source);
                self::assertNotNull($result->selected->grossProbability);
                self::assertSame(0, $result->selected->grossProbability->numerator);
                self::assertNull($result->selected->cumulativeReturnProbability);
                self::assertNotNull($result->selected->returnCurve);
                self::assertSame($returns[0] ?? null, $result->selected->returnCurve->source);
                self::assertSame([] === $returns ? 'return_training_row_missing' : 'returns_coverage_missing', $result->selected->returnCurve->unavailableReason);
                self::assertNotNull($result->selected->quantities);
                self::assertSame(100, $result->selected->quantities->eligibleOpenQuantity);
                self::assertSame('0', $result->selected->quantities->retainedOpenNumerator);
            }
        }
    }

    public function testUnreadyTargetsKeepTheirReasonsAndNeverAttemptTraining(): void
    {
        foreach ([0, 100, null] as $quantity) {
            foreach ([
                [self::target(quantity: $quantity, complete: false), 'target_input_incomplete'],
                [self::target(quantity: $quantity, reason: 'contradictory_quantity'), 'contradictory_quantity'],
                [self::target(quantity: $quantity, stage: 'unknown'), 'unsupported_target_stage'],
                [self::target(quantity: $quantity, reason: 'gross_probability_unavailable'), 'gross_probability_unavailable'],
            ] as [$target, $reason]) {
                self::assertUnavailable($reason, self::assess($target, self::batch([self::gross()], [self::returns()])));
            }
        }
        self::assertUnavailable('unknown_eligible_open_quantity', self::assess(self::target(quantity: null), self::batch()));
        self::assertUnavailable('training_context_missing', self::assess(self::target(), self::batch(noContext: true)));
    }

    public function testForeignTargetBatchAndNestedContextThrowBeforePrivateReasonsAndZero(): void
    {
        foreach ([self::context(company: self::FOREIGN), self::context(account: self::FOREIGN)] as $foreign) {
            foreach ([0, 100] as $quantity) {
                foreach ([
                    [self::target(quantity: $quantity, context: $foreign), self::batch()],
                    [self::target(quantity: $quantity, reason: 'private'), self::batch(company: self::FOREIGN)],
                    [self::target(quantity: $quantity, reason: 'private'), self::batch(account: self::FOREIGN)],
                    [self::target(quantity: $quantity, reason: 'private'), self::batch(context: $foreign, reason: 'private')],
                ] as [$target, $batch]) {
                    try {
                        self::assess($target, $batch);
                        self::fail('Foreign scope accepted.');
                    } catch (\InvalidArgumentException) {
                        self::addToAssertionCount(1);
                    }
                }
            }
        }
    }

    public function testInvalidTargetSnapshotsPrecedeZeroAndBatchMetadata(): void
    {
        foreach ([null, '', ' ', 'single_statement'] as $snapshot) {
            foreach ([0, 100] as $quantity) {
                self::assertUnavailable('target_snapshot_missing', self::assess(self::target(quantity: $quantity, context: self::context(snapshot: $snapshot)), self::batch(reason: 'private')));
            }
        }
    }

    public function testPresentContextMismatchPrecedesTargetZeroAndPrivateReasonsIncludingEpochMicros(): void
    {
        foreach ([self::context(snapshot: null), self::context(snapshot: ''), self::context(snapshot: 'single_statement'),
            self::context(snapshot: 'another-rr'), self::context(readAt: '2026-10-28T00:00:00.000001Z'),
            self::context(readAt: '2026-10-27T23:59:59.999999Z'), self::context(release: 'other'), self::context(epochId: self::FOREIGN),
            self::context(start: '2026-01-01T00:00:00.000001Z'), self::context(start: '2025-12-31T23:59:59.999999Z'),
            self::context(end: '2026-11-01T00:00:00Z'),
        ] as $context) {
            foreach ([0, 100] as $quantity) {
                self::assertUnavailable('training_context_mismatch', self::assess(self::target(quantity: $quantity, reason: 'private'), self::batch(context: $context, reason: 'private')));
            }
        }
        $targetContext = self::context(end: '2026-11-01T00:00:00Z');
        foreach (['2026-11-01T00:00:00.000001Z', '2026-10-31T23:59:59.999999Z'] as $end) {
            foreach ([0, 100] as $quantity) {
                self::assertUnavailable('training_context_mismatch', self::assess(self::target(quantity: $quantity, context: $targetContext), self::batch(context: self::context(end: $end))));
            }
        }
        foreach (['+1 microsecond', '-1 microsecond'] as $offset) {
            foreach ([0, 100] as $quantity) {
                foreach ([false, true] as $noContext) {
                    self::assertUnavailable('training_context_mismatch', self::assess(self::target(quantity: $quantity), self::batch(noContext: $noContext, readAt: self::context()->readAt->modify($offset))));
                }
            }
        }
    }

    public function testSourceReadinessFailuresRemainNullAndPreserveRawMetadata(): void
    {
        $budget = new NetTrainingBudgetFailure('outcomes', 101, 100);
        foreach ([
            [self::gross(p95: null), 'maturity_unavailable'],
            [self::gross(postings: 29), 'insufficient_sample'],
            [self::gross(quantity: 99, gross: 49, t2: 50), 'insufficient_sample'],
            [self::gross(budget: $budget), 'input_budget_exceeded'],
            [self::gross(reason: 'postings_coverage_missing'), 'postings_coverage_missing'],
        ] as [$row, $reason]) {
            $result = self::assess(self::target(), self::batch([$row], [self::returns()]));
            self::assertUnavailable('open_retention_unavailable', $result, 2);
            $first = $result->attempts[0]->assessment;
            self::assertSame($reason, $first->unavailableReason);
            self::assertNotNull($first->grossRate);
            self::assertSame($row, $first->grossRate->source);
            self::assertSame(3, $first->grossRate->source->excludedGroups);
            self::assertSame(2, $first->grossRate->source->excludedDays);
            self::assertSame($row->budgetFailure, $first->grossRate->source->budgetFailure);
            self::assertSame('gross_training_row_missing', $result->attempts[1]->assessment->unavailableReason);
        }
    }

    public function testPostHandoverRequiresItsOwnConditionalSample(): void
    {
        foreach ([self::gross(postings: 29, postHandover: 29), self::gross(postHandover: null), self::gross(quantity: 200, gross: 50, t1: 101, t2: 49)] as $row) {
            $result = self::assess(self::target(post: true), self::batch([$row], [self::returns()]));
            self::assertUnavailable('open_retention_unavailable', $result, 2);
            self::assertSame(null === $row->postHandoverPostings ? 'gross_training_counts_missing' : 'insufficient_sample', $result->attempts[0]->assessment->unavailableReason);
        }
    }

    public function testReturnBudgetsAndInsufficientSamplesDoNotBecomeZero(): void
    {
        foreach ([
            [self::returns(budget: new NetTrainingBudgetFailure('returns', 101, 100)), 'input_budget_exceeded'],
            [self::returns(quantity: 99), 'insufficient_sample'],
            [self::returns(postings: 29), 'insufficient_sample'],
            [self::returns(noProfile: true), 'return_training_profile_missing'],
        ] as [$row, $reason]) {
            $result = self::assess(self::target(), self::batch([self::gross()], [$row]));
            self::assertUnavailable('open_retention_unavailable', $result, 2);
            self::assertSame($reason, $result->attempts[0]->assessment->unavailableReason);
            self::assertSame($row, $result->attempts[0]->assessment->returnCurve?->source);
            self::assertSame($row->budgetFailure, $result->attempts[0]->assessment->returnCurve->source->budgetFailure);
        }
    }

    public function testMissingDuplicateAndUnrelatedRowsCannotServeAsASelectedSource(): void
    {
        foreach ([
            [self::batch([], []), 'gross_training_row_missing', 'return_training_row_missing'],
            [self::batch([self::gross(), self::gross()], [self::returns(), self::returns()]), 'gross_training_row_ambiguous', 'return_training_row_ambiguous'],
            [self::batch([self::gross(sku: 'other')], [self::returns(sku: 'other')]), 'gross_training_row_missing', 'return_training_row_missing'],
        ] as [$batch, $grossReason, $returnReason]) {
            $result = self::assess(self::target(), $batch);
            self::assertUnavailable('open_retention_unavailable', $result, 2);
            self::assertSame($grossReason, $result->attempts[0]->assessment->grossRate?->unavailableReason);
            self::assertSame($returnReason, $result->attempts[0]->assessment->returnCurve?->unavailableReason);
            self::assertNull($result->attempts[0]->assessment->grossRate->source);
            self::assertNull($result->attempts[0]->assessment->returnCurve->source);
            self::assertNull($result->attempts[1]->marketplaceSku);
            self::assertSame('gross_training_row_missing', $result->attempts[1]->assessment->grossRate?->unavailableReason);
        }
        $batchFailure = self::assess(self::target(), self::batch(reason: 'source_history_unavailable'));
        self::assertUnavailable('open_retention_unavailable', $batchFailure, 2);
        foreach ($batchFailure->attempts as $attempt) {
            self::assertSame('source_history_unavailable', $attempt->assessment->grossRate?->unavailableReason);
            self::assertSame('source_history_unavailable', $attempt->assessment->returnCurve?->unavailableReason);
        }
    }

    public function testAttemptRejectsMismatchingPresentScopeEvenForUnavailableAssessment(): void
    {
        $context = self::context();
        $rate = new BuildPreHandoverGrossRateAction()->build(self::COMPANY, self::ACCOUNT, self::batch(), self::SKU);
        $curve = new BuildReturnCurveAction()->build(self::COMPANY, self::ACCOUNT, self::batch(), self::SKU);
        $absent = new OpenRetentionAttempt(self::SKU, OpenRetentionAssessment::unavailable('missing'));
        self::assertSame(self::SKU, $absent->marketplaceSku);
        $badGrossRow = new ScopedPreHandoverGrossRate(self::COMPANY, self::ACCOUNT, $context->readAt, $context, self::SKU, self::gross(sku: null), null, 'private');
        $badReturnRow = new ScopedReturnCurve(self::COMPANY, self::ACCOUNT, $context->readAt, $context, self::SKU, self::returns(sku: null), null, 'private');
        foreach ([
            ['', OpenRetentionAssessment::unavailable('private')],
            [null, OpenRetentionAssessment::unavailable('private', $rate, $curve)],
            [self::SKU, OpenRetentionAssessment::unavailable('private', $badGrossRow)],
            [self::SKU, OpenRetentionAssessment::unavailable('private', null, $badReturnRow)],
            [self::SKU, OpenRetentionAssessment::unavailable('private', new ScopedPreHandoverGrossRate(self::COMPANY, self::ACCOUNT, $context->readAt, self::context(company: self::FOREIGN), self::SKU, null, null, 'private'))],
            [self::SKU, OpenRetentionAssessment::unavailable('private', new ScopedPreHandoverGrossRate(self::COMPANY, self::ACCOUNT, $context->readAt->modify('+1 microsecond'), $context, self::SKU, null, null, 'private'))],
            [self::SKU, OpenRetentionAssessment::unavailable('private', $rate, new ScopedReturnCurve(self::FOREIGN, self::ACCOUNT, $context->readAt, null, self::SKU, null, null, 'private'))],
            [self::SKU, OpenRetentionAssessment::unavailable('private', $rate, new ScopedReturnCurve(self::COMPANY, self::FOREIGN, $context->readAt, null, self::SKU, null, null, 'private'))],
            [self::SKU, OpenRetentionAssessment::unavailable('private', $rate, new ScopedReturnCurve(self::COMPANY, self::ACCOUNT, $context->readAt->modify('+1 microsecond'), null, self::SKU, null, null, 'private'))],
            [self::SKU, OpenRetentionAssessment::unavailable('private', $rate, new ScopedReturnCurve(self::COMPANY, self::ACCOUNT, $context->readAt, self::context(start: '2026-01-01T00:00:00.000001Z'), self::SKU, null, null, 'private'))],
            [self::SKU, OpenRetentionAssessment::unavailable('private', $rate, new ScopedReturnCurve(self::COMPANY, self::ACCOUNT, $context->readAt, self::context(end: '2026-11-01T00:00:00Z'), self::SKU, null, null, 'private'))],
            [self::SKU, self::zeroAssessment()],
        ] as [$sku, $assessment]) {
            try {
                new OpenRetentionAttempt($sku, $assessment);
                self::fail('Incompatible attempt accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testResolutionFactoriesRejectImpossibleSelectionAndAttemptOrder(): void
    {
        $available = self::assess(self::target(), self::batch([self::gross()], [self::returns()]));
        $selected = $available->selected;
        self::assertNotNull($selected);
        $account = self::assess(self::target(), self::batch([self::gross(sku: null)], [self::returns(sku: null)]));
        $accountSelected = $account->selected;
        self::assertNotNull($accountSelected);
        $skuFailure = new OpenRetentionAttempt(self::SKU, OpenRetentionAssessment::unavailable('sku_missing'));
        $accountFailure = new OpenRetentionAttempt(null, OpenRetentionAssessment::unavailable('account_missing'));
        $zero = self::zeroAssessment();
        $availableFactory = new \ReflectionMethod(OpenRetentionResolution::class, 'available');
        $invalid = [
            static fn () => OpenRetentionResolution::knownZero($selected),
            static fn () => OpenRetentionResolution::knownZero(OpenRetentionAssessment::unavailable('missing')),
            static fn () => OpenRetentionResolution::available($zero, []),
            static fn () => OpenRetentionResolution::available($selected, []),
            static fn () => OpenRetentionResolution::available(clone $selected, $available->attempts),
            static fn () => OpenRetentionResolution::available($selected, [$skuFailure]),
            static fn () => $availableFactory->invoke(null, $selected, [1 => $available->attempts[0]]),
            static fn () => OpenRetentionResolution::available($selected, [$available->attempts[0], $accountFailure]),
            static fn () => OpenRetentionResolution::available($accountSelected, [$available->attempts[0], $account->attempts[1]]),
            static fn () => OpenRetentionResolution::unavailable(''),
            static fn () => OpenRetentionResolution::unavailable(' '),
            static fn () => OpenRetentionResolution::unavailable('open_retention_unavailable', [$skuFailure]),
            static fn () => OpenRetentionResolution::unavailable('other', [$skuFailure, $accountFailure]),
            static fn () => OpenRetentionResolution::unavailable('open_retention_unavailable', [$accountFailure, $skuFailure]),
            static fn () => OpenRetentionResolution::unavailable('open_retention_unavailable', [$skuFailure, $skuFailure]),
            static fn () => OpenRetentionResolution::unavailable('open_retention_unavailable', [$skuFailure, $accountFailure, $accountFailure]),
            static fn () => OpenRetentionResolution::unavailable('open_retention_unavailable', [$available->attempts[0], $accountFailure]),
        ];
        foreach ($invalid as $factory) {
            try {
                $factory();
                self::fail('Contradictory resolution accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private static function assess(OpenTargetEvidence $target, NetTrainingBatch $batch): OpenRetentionResolution
    {
        $pre = new BuildPreHandoverGrossRateAction();
        $post = new BuildPostHandoverGrossRateAction();
        $curves = new BuildReturnCurveAction();

        return new AssessOpenRetentionAction($pre, $post, $curves, new ApplyOpenRetentionAction($pre, $post, $curves))->assess(self::COMPANY, self::ACCOUNT, $target, $batch);
    }

    private static function zeroAssessment(): OpenRetentionAssessment
    {
        return new ApplyOpenRetentionAction(new BuildPreHandoverGrossRateAction(), new BuildPostHandoverGrossRateAction(), new BuildReturnCurveAction())->apply(self::COMPANY, self::ACCOUNT, self::target(quantity: 0), null, null);
    }

    private static function assertUnavailable(string $reason, OpenRetentionResolution $result, int $attempts = 0): void
    {
        self::assertNull($result->selected);
        self::assertSame($reason, $result->unavailableReason);
        self::assertCount($attempts, $result->attempts);
    }

    private static function context(?string $snapshot = 'rr-b08', string $company = self::COMPANY, string $account = self::ACCOUNT, string $readAt = '2026-10-28T00:00:00Z', string $release = 'capture-release', string $epochId = '01900000-0000-7000-8000-000000000004', string $start = '2026-01-01T00:00:00Z', ?string $end = null): NetTrainingContext
    {
        return new NetTrainingContext($company, $account, new \DateTimeImmutable($readAt), new NetForecastCaptureEpoch($epochId, NetForecastCaptureEpoch::CAPTURE_VERSION, new \DateTimeImmutable($start), null === $end ? null : new \DateTimeImmutable($end), $release), $snapshot);
    }

    private static function target(?int $quantity = 100, bool $post = false, ?string $stage = null, bool $complete = true, ?string $reason = null, ?NetTrainingContext $context = null): OpenTargetEvidence
    {
        return new OpenTargetEvidence($context ?? self::context(), 'order-1', self::SKU, $stage ?? ($post ? OpenTargetEvidence::AFTER_HANDOVER : OpenTargetEvidence::BEFORE_HANDOVER), $quantity, $complete, $reason);
    }

    /**
     * @param list<GrossTrainingAggregateRow>  $gross
     * @param list<ReturnTrainingAggregateRow> $returns
     */
    private static function batch(array $gross = [], array $returns = [], ?NetTrainingContext $context = null, bool $noContext = false, ?\DateTimeImmutable $readAt = null, ?string $reason = null, string $company = self::COMPANY, string $account = self::ACCOUNT): NetTrainingBatch
    {
        $context ??= self::context();

        return new NetTrainingBatch($company, $account, $readAt ?? $context->readAt, $noContext ? null : $context, $gross, $returns, $reason);
    }

    private static function gross(?string $sku = self::SKU, int $quantity = 200, int $postings = 30, int $gross = 100, int $t1 = 0, int $t2 = 100, ?int $postHandover = 30, ?int $p95 = 86400, ?string $reason = null, ?NetTrainingBudgetFailure $budget = null): GrossTrainingAggregateRow
    {
        return new GrossTrainingAggregateRow($sku, true, $reason, $quantity, $postings, $gross, $t1, $t2, 0, $postHandover, $p95, 3, 2, $budget);
    }

    private static function returns(?string $sku = self::SKU, int $quantity = 100, int $postings = 30, bool $noProfile = false, ?string $reason = null, ?NetTrainingBudgetFailure $budget = null): ReturnTrainingAggregateRow
    {
        $profile = [];
        for ($age = 0; $age <= 60; ++$age) {
            $returned = 0 === $age ? 0 : 20;
            $profile[] = new ReturnTrainingAgeRow($age, $returned, $quantity - $returned, min($postings, $quantity - $returned));
        }

        return new ReturnTrainingAggregateRow($sku, true, $reason, $quantity, $postings, $noProfile ? null : $profile, 4, 'posting_capacity_lower_bound_v1', $budget);
    }
}
