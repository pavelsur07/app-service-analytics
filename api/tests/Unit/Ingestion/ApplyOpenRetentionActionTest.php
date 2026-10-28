<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Application\NetForecast\ApplyOpenRetentionAction;
use App\Ingestion\Application\NetForecast\BuildPostHandoverGrossRateAction;
use App\Ingestion\Application\NetForecast\BuildPreHandoverGrossRateAction;
use App\Ingestion\Application\NetForecast\BuildReturnCurveAction;
use App\Ingestion\Application\NetForecast\NetTrainingBatch;
use App\Ingestion\Application\NetForecast\OpenRetentionAssessment;
use App\Ingestion\Application\NetForecast\OpenTargetEvidence;
use App\Ingestion\Application\NetForecast\ScopedPostHandoverGrossRate;
use App\Ingestion\Application\NetForecast\ScopedPreHandoverGrossRate;
use App\Ingestion\Application\NetForecast\ScopedReturnCurve;
use App\Ingestion\Domain\ValueObject\GrossProbability;
use App\Ingestion\Domain\ValueObject\NetForecastCaptureEpoch;
use App\Ingestion\Domain\ValueObject\ObservedReturnCurve;
use App\Ingestion\Domain\ValueObject\OpenRetainedQuantities;
use App\Ingestion\Domain\ValueObject\ReturnProbability;
use App\Ingestion\Infrastructure\Query\NetForecast\GrossTrainingAggregateRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingBudgetFailure;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;
use App\Ingestion\Infrastructure\Query\NetForecast\ReturnTrainingAgeRow;
use App\Ingestion\Infrastructure\Query\NetForecast\ReturnTrainingAggregateRow;
use PHPUnit\Framework\TestCase;

final class ApplyOpenRetentionActionTest extends TestCase
{
    private const string COMPANY = '01900000-0000-7000-8000-000000000001';
    private const string ACCOUNT = '01900000-0000-7000-8000-000000000002';
    private const string FOREIGN = '01900000-0000-7000-8000-000000000003';
    private const string SKU = '00123';

    public function testBothStagesUseSameFutureDeliveryHorizonWithoutSubtractingKnownReturns(): void
    {
        $curve = self::returns();
        foreach ([[false, '50', '10', '40'], [true, '100', '20', '80']] as [$post, $gross, $returns, $retained]) {
            $rate = self::gross(post: $post);
            $result = self::apply(self::target(post: $post), $rate, $curve);
            self::assertNull($result->unavailableReason);
            self::assertSame('sku', $result->basis);
            self::assertNotNull($result->quantities);
            self::assertSame($gross, $result->quantities->expectedGrossNumerator);
            self::assertSame($returns, $result->quantities->expectedOpenReturnsNumerator);
            self::assertSame($retained, $result->quantities->retainedOpenNumerator);
            self::assertNotNull($result->cumulativeReturnProbability);
            self::assertSame(20, $result->cumulativeReturnProbability->numerator);
            self::assertSame(100, $result->cumulativeReturnProbability->denominator);
            self::assertSame($rate, $result->grossRate);
            self::assertSame($curve, $result->returnCurve);
        }
    }

    public function testWholeAccountPairIsAvailableButNoFallbackIsPerformed(): void
    {
        $gross = self::gross(sku: null, source: self::row(sku: null));
        $returns = self::returns(sku: null, source: self::returnRow(sku: null));
        $result = self::apply(self::target(), $gross, $returns);
        self::assertSame('account', $result->basis);
        self::assertNotNull($result->quantities);
        self::assertSame('40', $result->quantities->retainedOpenNumerator);
        self::assertSame('insufficient_sample', self::apply(self::target(), self::gross(source: self::row(postings: 29)), self::returns())->unavailableReason);
    }

    public function testStageAndSelectedSkuMismatchCannotBeAppliedEvenWithGenuineGrossZero(): void
    {
        foreach ([
            [self::target(), self::gross(post: true), self::returns(), 'gross_stage_mismatch'],
            [self::target(post: true), self::gross(), self::returns(), 'gross_stage_mismatch'],
            [self::target(), self::gross(sku: 'other'), self::returns(), 'selected_scope_mismatch'],
            [self::target(), self::gross(), self::returns(sku: null), 'selected_scope_mismatch'],
            [self::target(), self::gross(sku: null), self::returns(), 'selected_scope_mismatch'],
            [self::target(), self::gross(source: self::row(sku: 'other')), self::returns(), 'selected_scope_mismatch'],
            [self::target(), self::gross(), self::returns(source: self::returnRow(sku: 'other')), 'selected_scope_mismatch'],
            [self::target(), self::zeroGross(), self::returns(sku: null), 'selected_scope_mismatch'],
        ] as [$target, $gross, $returns, $reason]) {
            self::assertUnavailable($reason, self::apply($target, $gross, $returns), $gross, $returns);
        }
    }

    public function testSelectedRawFractionsRemainExactThroughApplication(): void
    {
        $gross = self::gross(source: self::row(quantity: 300, t1: 200), probability: new GrossProbability(100, 300));
        $result = self::apply(self::target(), $gross, self::returns());
        self::assertNotNull($result->quantities);
        self::assertSame('100', $result->quantities->expectedGrossNumerator);
        self::assertSame('3', $result->quantities->expectedGrossDenominator);
        self::assertSame('20', $result->quantities->expectedOpenReturnsNumerator);
        self::assertSame('3', $result->quantities->expectedOpenReturnsDenominator);
        self::assertSame('80', $result->quantities->retainedOpenNumerator);
        self::assertSame('3', $result->quantities->retainedOpenDenominator);
        self::assertNotNull($result->grossProbability);
        self::assertSame(100, $result->grossProbability->numerator);
        self::assertSame(300, $result->grossProbability->denominator);
    }

    public function testCachedCurveMustMatchWholeRawProfileEvenWithIdenticalHorizonCoefficient(): void
    {
        $source = self::returnRow();
        $points = $source->ageProfile;
        self::assertNotNull($points);
        $points[1] = new ReturnTrainingAgeRow(1, 10, 90, 30);
        $changed = new ReturnTrainingAggregateRow(self::SKU, true, null, 100, 30, array_values($points), 0);
        $gross = self::gross();
        $returns = self::returns(source: $changed);
        self::assertUnavailable('invalid_return_curve', self::apply(self::target(), $gross, $returns), $gross, $returns);
    }

    public function testTrustedTargetZeroNeedsNoTrainingOrUnusedPairButRequiresKnownCompleteSupportedTarget(): void
    {
        foreach ([[null, null],
            [self::gross(post: true, noContext: true, noSource: true, noProbability: true, reason: 'source_history_unavailable'), self::returns(sku: null, noContext: true, noSource: true, noCurve: true)],
        ] as [$gross, $returns]) {
            $result = self::apply(self::target(quantity: 0), $gross, $returns);
            self::assertSame('no_eligible_open', $result->basis);
            self::assertEquals(OpenRetainedQuantities::zero(), $result->quantities);
            self::assertNull($result->grossProbability);
            self::assertNull($result->cumulativeReturnProbability);
            self::assertSame($gross, $result->grossRate);
            self::assertSame($returns, $result->returnCurve);
        }
        foreach ([[self::target(quantity: null), 'unknown_eligible_open_quantity'],
            [self::target(quantity: 0, complete: false), 'target_input_incomplete'],
            [self::target(quantity: 0, reason: 'contradictory_quantity'), 'contradictory_quantity'],
            [self::target(quantity: 0, stage: 'unknown'), 'unsupported_target_stage'],
        ] as [$target, $reason]) {
            self::assertUnavailable($reason, self::apply($target, null, null));
        }
    }

    public function testGenuineGrossZeroWorksWithoutReturnSampleAndPreservesUnknownF(): void
    {
        foreach ([false, true] as $post) {
            $gross = self::zeroGross($post);
            foreach ([null, self::returns(noCurve: true, noSource: true, noContext: true, reason: 'returns_coverage_missing'),
                self::returns(source: self::returnRow(budget: new NetTrainingBudgetFailure('returns', 101, 100))),
            ] as $returns) {
                $result = self::apply(self::target(post: $post), $gross, $returns);
                self::assertNull($result->unavailableReason);
                self::assertSame('sku', $result->basis);
                self::assertNotNull($result->grossProbability);
                self::assertSame(0, $result->grossProbability->numerator);
                self::assertNull($result->cumulativeReturnProbability);
                self::assertNotNull($result->quantities);
                self::assertSame(100, $result->quantities->eligibleOpenQuantity);
                self::assertSame('0', $result->quantities->retainedOpenNumerator);
                self::assertSame('0', $result->quantities->expectedOpenReturnsNumerator);
                self::assertSame($returns, $result->returnCurve);
            }
        }
    }

    public function testGrossReadinessCannotBeBypassedByCachedZeroOrAnotherProbability(): void
    {
        foreach ([
            [null, 'gross_probability_unavailable'],
            [self::gross(noContext: true), 'training_context_missing'],
            [self::gross(noSource: true), 'gross_training_row_missing'],
            [self::gross(noProbability: true), 'gross_probability_unavailable'],
            [self::gross(reason: 'source_history_unavailable'), 'source_history_unavailable'],
            [self::gross(source: self::row(complete: false)), 'gross_training_input_incomplete'],
            [self::gross(source: self::row(reason: 'postings_coverage_missing')), 'postings_coverage_missing'],
            [self::gross(source: self::row(p95: null)), 'maturity_unavailable'],
            [self::gross(source: self::row(budget: new NetTrainingBudgetFailure('outcomes', 101, 100))), 'input_budget_exceeded'],
            [self::gross(source: self::row(postings: 29)), 'insufficient_sample'],
            [self::gross(source: self::row(quantity: 99, gross: 49, t1: 50, postings: 30), probability: new GrossProbability(0, 99)), 'insufficient_sample'],
            [self::gross(probability: new GrossProbability(0, 200)), 'invalid_gross_probability'],
            [self::gross(probability: new GrossProbability(1, 2)), 'invalid_gross_probability'],
            [self::gross(source: self::row(quantity: 100)), 'invalid_gross_training_counts'],
            [self::gross(post: true, source: self::row(postHandover: null)), 'gross_training_counts_missing'],
        ] as [$gross, $reason]) {
            self::assertUnavailable($reason, self::apply(self::target(post: $gross instanceof ScopedPostHandoverGrossRate), $gross, null), $gross);
        }
    }

    public function testPositiveGrossRequiresUsableWholeReturnPopulationAndCumulativeH(): void
    {
        foreach ([
            [null, 'return_curve_unavailable'],
            [self::returns(noContext: true), 'training_context_missing'],
            [self::returns(reason: 'returns_coverage_missing'), 'returns_coverage_missing'],
            [self::returns(noSource: true), 'return_training_row_missing'],
            [self::returns(noCurve: true), 'return_training_profile_missing'],
            [self::returns(source: self::returnRow(complete: false)), 'return_training_input_incomplete'],
            [self::returns(source: self::returnRow(reason: 'source_history_unavailable')), 'source_history_unavailable'],
            [self::returns(source: self::returnRow(budget: new NetTrainingBudgetFailure('returns', 101, 100))), 'input_budget_exceeded'],
            [self::returns(source: self::returnRow(excluded: -1)), 'invalid_return_training_counts'],
            [self::returns(source: self::returnRow(method: 'other')), 'unsupported_risk_posting_method'],
            [self::returns(source: self::returnRow(noProfile: true)), 'return_training_profile_missing'],
            [self::returns(source: self::returnRow(returned: 101)), 'invalid_return_training_profile'],
            [self::returns(source: self::returnRow(returned: 30)), 'invalid_return_curve'],
            [self::returns(source: self::returnRow(quantity: 99), matchingCurve: true), 'insufficient_sample'],
            [self::returns(source: self::returnRow(postings: 29), matchingCurve: true), 'insufficient_sample'],
        ] as [$returns, $reason]) {
            $gross = self::gross();
            self::assertUnavailable($reason, self::apply(self::target(), $gross, $returns), $gross, $returns);
        }
        foreach ([[0, '50'], [100, '0']] as [$returned, $retained]) {
            $result = self::apply(self::target(), self::gross(), self::returns(source: self::returnRow(returned: $returned), matchingCurve: true));
            self::assertNotNull($result->quantities);
            self::assertSame($retained, $result->quantities->retainedOpenNumerator);
        }
    }

    public function testInvalidTargetSnapshotsPrecedeZeroAndSourceReasons(): void
    {
        foreach ([null, '', ' ', 'single_statement'] as $snapshot) {
            $gross = self::gross(reason: 'private');
            self::assertUnavailable('target_snapshot_missing', self::apply(self::target(quantity: 0, context: self::context(snapshot: $snapshot)), $gross, null), $gross);
        }
    }

    public function testPresentContextMismatchPrecedesBothZeroShortcuts(): void
    {
        foreach ([self::context(snapshot: null), self::context(snapshot: ''), self::context(snapshot: 'single_statement'),
            self::context(snapshot: 'other-rr'), self::context(readAt: '2026-10-28T00:00:00.000001Z'),
            self::context(release: 'other'), self::context(epochId: self::FOREIGN),
            self::context(start: '2026-01-02T00:00:00Z'), self::context(end: '2026-11-01T00:00:00Z'),
            self::context(start: '2026-01-01T00:00:00.000001Z'),
        ] as $context) {
            foreach ([0, 100] as $quantity) {
                foreach ([[self::gross(context: $context), null], [self::zeroGross(), self::returns(context: $context)]] as [$gross, $returns]) {
                    self::assertUnavailable('training_context_mismatch', self::apply(self::target(quantity: $quantity), $gross, $returns), $gross, $returns);
                }
            }
        }
        foreach ([[self::gross(noContext: true, readAt: self::context()->readAt->modify('+1 microsecond')), null],
            [self::zeroGross(), self::returns(noContext: true, readAt: self::context()->readAt->modify('-1 microsecond'))],
        ] as [$gross, $returns]) {
            self::assertUnavailable('training_context_mismatch', self::apply(self::target(quantity: 0), $gross, $returns), $gross, $returns);
        }
        $targetContext = self::context(end: '2026-11-01T00:00:00Z');
        $curve = self::returns(context: self::context(end: '2026-11-01T00:00:00.000001Z'));
        foreach ([0, 100] as $quantity) {
            $gross = self::gross(source: self::row(gross: 0, t2: 100), probability: new GrossProbability(0, 200), context: $targetContext);
            self::assertUnavailable('training_context_mismatch', self::apply(self::target(quantity: $quantity, context: $targetContext), $gross, $curve), $gross, $curve);
        }
    }

    public function testEveryForeignPresentScopeThrowsBeforePrivateMetadataAndZero(): void
    {
        foreach ([self::context(company: self::FOREIGN), self::context(account: self::FOREIGN)] as $foreign) {
            foreach ([0, 100] as $quantity) {
                $gross = self::zeroGross();
                foreach ([
                    [self::target(quantity: $quantity, context: $foreign), $gross, null],
                    [self::target(quantity: $quantity, reason: 'private'), self::gross(company: self::FOREIGN, reason: 'private'), null],
                    [self::target(quantity: $quantity), self::gross(account: self::FOREIGN), null],
                    [self::target(quantity: $quantity), self::gross(context: $foreign), null],
                    [self::target(quantity: $quantity), $gross, self::returns(company: self::FOREIGN, reason: 'private')],
                    [self::target(quantity: $quantity), $gross, self::returns(account: self::FOREIGN)],
                    [self::target(quantity: $quantity), $gross, self::returns(context: $foreign)],
                ] as [$target, $rate, $returns]) {
                    try {
                        self::apply($target, $rate, $returns);
                        self::fail('Foreign evidence accepted.');
                    } catch (\InvalidArgumentException) {
                        self::addToAssertionCount(1);
                    }
                }
            }
        }
    }

    public function testFactoriesRejectContradictoryResultAndEmptyReason(): void
    {
        $gross = self::gross();
        $returns = self::returns();
        $probability = new GrossProbability(100, 200);
        $f = new ReturnProbability(20, 100);
        $quantity = OpenRetainedQuantities::fromProbabilities(100, $probability, $f);
        foreach ([
            [$quantity, $probability, $f, 'other', $gross, $returns],
            [$quantity, null, $f, 'sku', $gross, $returns],
            [$quantity, $probability, null, 'sku', $gross, $returns],
            [$quantity, $probability, $f, 'account', $gross, $returns],
            [$quantity, $probability, $f, 'sku', null, $returns],
            [$quantity, $probability, $f, 'sku', $gross, null],
            [$quantity, $probability, $f, 'no_eligible_open', $gross, $returns],
            [OpenRetainedQuantities::zero(), $probability, $f, 'sku', $gross, $returns],
            [OpenRetainedQuantities::zero(), $probability, null, 'no_eligible_open', $gross, $returns],
            [$quantity, $probability, new ReturnProbability(0, 100), 'sku', $gross, $returns],
            [OpenRetainedQuantities::fromProbabilities(100, new GrossProbability(1, 3), $f), $probability, $f, 'sku', $gross, $returns],
            [$quantity, $probability, $f, 'sku', $gross, self::returns(sku: null)],
        ] as [$quantities, $p, $cumulative, $basis, $rate, $curve]) {
            try {
                OpenRetentionAssessment::available($quantities, $p, $cumulative, $basis, $rate, $curve);
                self::fail('Contradictory result accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        foreach (['', ' '] as $reason) {
            try {
                OpenRetentionAssessment::unavailable($reason);
                self::fail('Empty reason accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private static function apply(OpenTargetEvidence $target, ScopedPreHandoverGrossRate|ScopedPostHandoverGrossRate|null $gross, ?ScopedReturnCurve $returns): OpenRetentionAssessment
    {
        return new ApplyOpenRetentionAction(new BuildPreHandoverGrossRateAction(), new BuildPostHandoverGrossRateAction(), new BuildReturnCurveAction())->apply(self::COMPANY, self::ACCOUNT, $target, $gross, $returns);
    }

    private static function assertUnavailable(string $reason, OpenRetentionAssessment $result, ScopedPreHandoverGrossRate|ScopedPostHandoverGrossRate|null $gross = null, ?ScopedReturnCurve $returns = null): void
    {
        self::assertSame($reason, $result->unavailableReason);
        self::assertNull($result->quantities);
        self::assertNull($result->grossProbability);
        self::assertNull($result->cumulativeReturnProbability);
        self::assertNull($result->basis);
        self::assertSame($gross, $result->grossRate);
        self::assertSame($returns, $result->returnCurve);
    }

    private static function context(?string $snapshot = 'rr-b07', string $company = self::COMPANY, string $account = self::ACCOUNT, string $readAt = '2026-10-28T00:00:00Z', string $release = 'capture-release', string $epochId = '01900000-0000-7000-8000-000000000004', string $start = '2026-01-01T00:00:00Z', ?string $end = null): NetTrainingContext
    {
        return new NetTrainingContext($company, $account, new \DateTimeImmutable($readAt), new NetForecastCaptureEpoch($epochId, NetForecastCaptureEpoch::CAPTURE_VERSION, new \DateTimeImmutable($start), null === $end ? null : new \DateTimeImmutable($end), $release), $snapshot);
    }

    private static function target(?int $quantity = 100, bool $post = false, ?string $stage = null, bool $complete = true, ?string $reason = null, ?NetTrainingContext $context = null): OpenTargetEvidence
    {
        return new OpenTargetEvidence($context ?? self::context(), 'order-1', self::SKU, $stage ?? ($post ? OpenTargetEvidence::AFTER_HANDOVER : OpenTargetEvidence::BEFORE_HANDOVER), $quantity, $complete, $reason);
    }

    private static function row(?string $sku = self::SKU, int $quantity = 200, int $postings = 60, int $gross = 100, int $t1 = 100, int $t2 = 0, ?int $postHandover = 30, ?int $p95 = 86400, bool $complete = true, ?string $reason = null, ?NetTrainingBudgetFailure $budget = null): GrossTrainingAggregateRow
    {
        return new GrossTrainingAggregateRow($sku, $complete, $reason, $quantity, $postings, $gross, $t1, $t2, 0, $postHandover, $p95, 3, 2, $budget);
    }

    private static function gross(bool $post = false, ?string $sku = self::SKU, ?GrossTrainingAggregateRow $source = null, bool $noSource = false, ?GrossProbability $probability = null, bool $noProbability = false, ?string $reason = null, ?NetTrainingContext $context = null, bool $noContext = false, ?\DateTimeImmutable $readAt = null, string $company = self::COMPANY, string $account = self::ACCOUNT): ScopedPreHandoverGrossRate|ScopedPostHandoverGrossRate
    {
        $context ??= self::context();
        $source = $noSource ? null : ($source ?? self::row());
        $probability = $noProbability ? null : ($probability ?? new GrossProbability(100, $post ? 100 : 200));

        return $post
            ? new ScopedPostHandoverGrossRate($company, $account, $readAt ?? $context->readAt, $noContext ? null : $context, $sku, $source, $probability, $reason)
            : new ScopedPreHandoverGrossRate($company, $account, $readAt ?? $context->readAt, $noContext ? null : $context, $sku, $source, $probability, $reason);
    }

    private static function zeroGross(bool $post = false): ScopedPreHandoverGrossRate|ScopedPostHandoverGrossRate
    {
        return self::gross(post: $post, source: self::row(gross: 0, t2: 100), probability: new GrossProbability(0, $post ? 100 : 200));
    }

    private static function returnRow(?string $sku = self::SKU, int $quantity = 100, int $postings = 30, int $returned = 20, bool $complete = true, ?string $reason = null, bool $noProfile = false, ?NetTrainingBudgetFailure $budget = null, int $excluded = 0, string $method = 'posting_capacity_lower_bound_v1'): ReturnTrainingAggregateRow
    {
        $points = [];
        for ($age = 0; $age <= 60; ++$age) {
            $r = 0 === $age ? 0 : $returned;
            $points[] = new ReturnTrainingAgeRow($age, $r, $quantity - $r, min($postings, max(0, $quantity - $r)));
        }

        return new ReturnTrainingAggregateRow($sku, $complete, $reason, $quantity, $postings, $noProfile ? null : $points, $excluded, $method, $budget);
    }

    private static function returns(?string $sku = self::SKU, ?ReturnTrainingAggregateRow $source = null, bool $noSource = false, bool $noCurve = false, bool $matchingCurve = false, ?string $reason = null, ?NetTrainingContext $context = null, bool $noContext = false, ?\DateTimeImmutable $readAt = null, string $company = self::COMPANY, string $account = self::ACCOUNT): ScopedReturnCurve
    {
        $context ??= self::context();
        $source ??= self::returnRow();
        $built = new BuildReturnCurveAction()->build(self::COMPANY, self::ACCOUNT, new NetTrainingBatch(self::COMPANY, self::ACCOUNT, self::context()->readAt, self::context(), [], [$matchingCurve ? $source : self::returnRow()]), $matchingCurve ? $source->marketplaceSku : self::SKU);
        self::assertInstanceOf(ObservedReturnCurve::class, $built->curve);

        return new ScopedReturnCurve($company, $account, $readAt ?? $context->readAt, $noContext ? null : $context, $sku, $noSource ? null : $source, $noCurve ? null : $built->curve, $reason);
    }
}
