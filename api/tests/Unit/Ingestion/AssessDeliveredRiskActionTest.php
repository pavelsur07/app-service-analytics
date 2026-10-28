<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Application\NetForecast\AssessDeliveredRiskAction;
use App\Ingestion\Application\NetForecast\BuildReturnCurveAction;
use App\Ingestion\Application\NetForecast\DeliveredRiskAssessment;
use App\Ingestion\Application\NetForecast\DeliveredTargetEvidence;
use App\Ingestion\Application\NetForecast\DeliveredTargetReadiness;
use App\Ingestion\Application\NetForecast\NetTrainingBatch;
use App\Ingestion\Domain\ValueObject\NetForecastCaptureEpoch;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;
use App\Ingestion\Infrastructure\Query\NetForecast\ReturnTrainingAgeRow;
use App\Ingestion\Infrastructure\Query\NetForecast\ReturnTrainingAggregateRow;
use PHPUnit\Framework\TestCase;

final class AssessDeliveredRiskActionTest extends TestCase
{
    private const string COMPANY = '01900000-0000-7000-8000-000000000001';
    private const string ACCOUNT = '01900000-0000-7000-8000-000000000002';
    private const string FOREIGN = '01900000-0000-7000-8000-000000000003';
    private const string ORDER = 'order-1';
    private const string SKU = '00123';

    public function testSkuConditionalGoldensUseCurrentDWithoutSubtractingKnownReturnsTwice(): void
    {
        foreach ([[80, 20, 30, '10', '70'], [70, 30, 35, '5', '65']] as [$quantity, $returned, $horizon, $future, $retained]) {
            $sku = self::row(self::SKU, returned: $returned, horizon: $horizon);
            $batch = self::batch([$sku, self::row(null, horizon: 60)]);
            $result = self::assess(self::target(quantity: $quantity), $batch);
            self::assertSame('sku', $result->basis);
            self::assertNotNull($result->quantities);
            self::assertNotNull($result->probability);
            self::assertSame(20, $result->ageDays);
            self::assertSame($future, $result->quantities->futureReturnsNumerator);
            self::assertSame($retained, $result->quantities->retainedDeliveredNumerator);
            self::assertSame($quantity, $result->probability->denominator);
            self::assertCount(1, $result->attempts);
            self::assertSame($sku, $result->attempts[0]->source->source);
            self::assertSame($batch->context, $result->attempts[0]->source->context);
        }
    }

    public function testFreshDeliveryDoesNotWaitForItsOwnHorizon(): void
    {
        foreach ([0, 1, 59] as $age) {
            $result = self::assess(self::target(age: $age), self::batch([self::row(self::SKU)]));
            self::assertNotNull($result->quantities);
            self::assertSame($age, $result->ageDays);
        }
        $context = self::context();
        $target = self::target(origin: $context->readAt->modify('-60 days +1 microsecond'));
        self::assertSame(59, self::assess($target, self::batch([self::row(self::SKU)]))->ageDays);
        $target = self::target(origin: $context->readAt->modify('-1 day +1 microsecond'));
        self::assertSame(0, self::assess($target, self::batch([self::row(self::SKU)]))->ageDays);
    }

    public function testConfirmedZeroRequiresNeitherTrainingNorInventedProbability(): void
    {
        $context = self::context();
        $unavailable = new NetTrainingBatch(self::COMPANY, self::ACCOUNT, $context->readAt, null, [], [], 'source_history_unavailable');
        $result = self::assess(self::target(quantity: 0, unknownOrigin: true), $unavailable);
        self::assertSame('no_remaining_delivery', $result->basis);
        self::assertNotNull($result->quantities);
        self::assertSame('0', $result->quantities->futureReturnsNumerator);
        self::assertSame('0', $result->quantities->retainedDeliveredNumerator);
        self::assertSame('1', $result->quantities->futureReturnsDenominator);
        self::assertSame('1', $result->quantities->retainedDeliveredDenominator);
        self::assertNull($result->probability);
        self::assertSame([], $result->attempts);
        self::assertNull($result->ageDays);
    }

    public function testUnknownContradictoryAndFutureInputsNeverBecomeZero(): void
    {
        foreach ([
            [self::target(quantity: null), 'unknown_outcome'],
            [self::target(quantity: 0, complete: false), 'target_input_incomplete'],
            [self::target(quantity: 0, reason: 'ambiguous_delivery_allocation'), 'ambiguous_delivery_allocation'],
            [self::target(unknownOrigin: true), 'delivery_observation_unknown'],
            [self::target(age: -1), 'delivery_observation_in_future'],
            [self::target(quantity: 0, age: -1), 'delivery_observation_in_future'],
            [self::target(origin: new \DateTimeImmutable('2025-01-01T00:00:00Z')), 'source_history_unavailable'],
        ] as [$target, $reason]) {
            $result = self::assess($target, self::batch([self::row(self::SKU)]));
            self::assertNull($result->quantities);
            self::assertSame($reason, $result->unavailableReason);
        }
    }

    public function testHorizonRequiresLagAndTargetEvidenceInsteadOfElapsedAge(): void
    {
        foreach ([60, 66] as $age) {
            $result = self::assess(self::target(age: $age, readiness: self::readiness($age)), self::batch([]));
            self::assertNull($result->quantities);
            self::assertSame('target_horizon_not_ready', $result->unavailableReason);
        }
        foreach ([67, 90] as $age) {
            $missing = self::assess(self::target(age: $age), self::batch([]));
            self::assertSame('target_readiness_missing', $missing->unavailableReason);
            $target = self::target(quantity: 70, age: $age, readiness: self::readiness($age));
            $context = self::context();
            $noTraining = new NetTrainingBatch(self::COMPANY, self::ACCOUNT, $context->readAt, null, [], [], 'source_history_unavailable');
            $ready = self::assess($target, $noTraining);
            self::assertSame('mature_target', $ready->basis);
            self::assertNotNull($ready->quantities);
            self::assertNotNull($ready->probability);
            self::assertSame(60, $ready->ageDays);
            self::assertSame('0', $ready->quantities->futureReturnsNumerator);
            self::assertSame('70', $ready->quantities->retainedDeliveredNumerator);
            self::assertSame(0, $ready->probability->numerator);
            self::assertSame([], $ready->attempts);
        }
    }

    public function testIncompleteOrMisboundReadinessCannotProveZero(): void
    {
        foreach ([
            [self::readiness(67, throughDay: 60), 'returns_coverage_missing'],
            [self::readiness(67, deepDay: 66), 'returns_coverage_missing'],
            [self::readiness(67, deepDay: null), 'returns_coverage_missing'],
            [self::readiness(67, throughDay: 68), 'target_evidence_in_future'],
            [self::readiness(67, deepDay: 68), 'target_evidence_in_future'],
            [self::readiness(67, fromDay: 1), 'returns_coverage_missing'],
            [self::readiness(67, history: false), 'source_history_unavailable'],
            [self::readiness(67, postings: false), 'postings_coverage_missing'],
            [self::readiness(67, returns: false), 'returns_coverage_missing'],
            [self::readiness(67, policy: 'other'), 'unsupported_target_coverage_policy'],
            [self::readiness(67, order: 'other-order'), 'target_readiness_mismatch'],
            [self::readiness(67, sku: 'other-sku'), 'target_readiness_mismatch'],
            [self::readiness(68), 'target_readiness_mismatch'],
            [self::readiness(67, context: self::context(snapshot: 'different')), 'target_readiness_mismatch'],
        ] as [$readiness, $reason]) {
            $result = self::assess(self::target(age: 67, readiness: $readiness), self::batch([]));
            self::assertSame($reason, $result->unavailableReason);
            self::assertNull($result->quantities);
        }
    }

    public function testFallbackTakesWholeAccountEstimateAndKeepsFailedScopeDiagnostics(): void
    {
        foreach ([
            [self::row(self::SKU, quantity: 99), 'insufficient_sample'],
            [self::row(self::SKU, postings: 29), 'insufficient_sample'],
            [self::row(self::SKU, returned: 71, horizon: 80), 'insufficient_risk_set'],
            [self::row(self::SKU, bound: 9), 'insufficient_risk_set'],
            [self::row(self::SKU, complete: false, reason: 'returns_coverage_missing'), 'returns_coverage_missing'],
        ] as [$sku, $reason]) {
            $account = self::row(null, quantity: 200, returned: 30, horizon: 47, bound: 15);
            $batch = self::batch([$sku, $account]);
            $result = self::assess(self::target(quantity: 170), $batch);
            self::assertSame('account', $result->basis);
            self::assertNotNull($result->quantities);
            self::assertNotNull($result->probability);
            self::assertSame('17', $result->quantities->futureReturnsNumerator);
            self::assertSame('153', $result->quantities->retainedDeliveredNumerator);
            self::assertSame(17, $result->probability->numerator);
            self::assertSame(170, $result->probability->denominator);
            self::assertCount(2, $result->attempts);
            self::assertSame($reason, $result->attempts[0]->assessment->unavailableReason);
            self::assertSame($sku, $result->attempts[0]->source->source);
            self::assertSame($account, $result->attempts[1]->source->source);
            self::assertSame($batch->context, $result->attempts[1]->source->context);
        }
    }

    public function testSampleRiskBoundaryAndRealZeroAreInclusive(): void
    {
        $result = self::assess(self::target(quantity: 30), self::batch([self::row(self::SKU, returned: 70, horizon: 70, bound: 10)]));
        self::assertSame('sku', $result->basis);
        self::assertNotNull($result->quantities);
        self::assertNotNull($result->probability);
        self::assertSame(0, $result->probability->numerator);
        self::assertSame(30, $result->probability->denominator);
        self::assertSame('30', $result->quantities->retainedDeliveredNumerator);
        $bothBad = self::assess(self::target(), self::batch([self::row(self::SKU, quantity: 99), self::row(null, bound: 9)]));
        self::assertNull($bothBad->quantities);
        self::assertSame('return_risk_unavailable', $bothBad->unavailableReason);
        self::assertSame('insufficient_sample', $bothBad->attempts[0]->assessment->unavailableReason);
        self::assertSame('insufficient_risk_set', $bothBad->attempts[1]->assessment->unavailableReason);
    }

    public function testMissingTrainingContextDoesNotFabricateAFreshCoefficient(): void
    {
        $context = self::context();
        $batch = new NetTrainingBatch(self::COMPANY, self::ACCOUNT, $context->readAt, null, [], [self::row(self::SKU)]);
        $result = self::assess(self::target(), $batch);
        self::assertNull($result->quantities);
        self::assertSame('training_context_missing', $result->attempts[0]->assessment->unavailableReason);
        self::assertSame('training_context_missing', $result->attempts[1]->assessment->unavailableReason);
    }

    public function testMissingOrAmbiguousSkuSelectsAccountWithoutMergingRows(): void
    {
        $account = self::row(null, returned: 30, horizon: 35);
        foreach ([[$account], [self::row(self::SKU), self::row(self::SKU), $account]] as $rows) {
            $result = self::assess(self::target(quantity: 70), self::batch($rows));
            self::assertSame('account', $result->basis);
            self::assertNotNull($result->quantities);
            self::assertNotNull($result->probability);
            self::assertSame('5', $result->quantities->futureReturnsNumerator);
            self::assertSame('65', $result->quantities->retainedDeliveredNumerator);
            self::assertSame(1 === \count($rows) ? 'return_training_row_missing' : 'return_training_row_ambiguous', $result->attempts[0]->assessment->unavailableReason);
            self::assertSame($account, $result->attempts[1]->source->source);
        }
    }

    public function testForeignReadinessIsRejectedBeforeZeroShortcut(): void
    {
        foreach ([self::context(company: self::FOREIGN), self::context(account: self::FOREIGN)] as $context) {
            $target = self::target(quantity: 0, readiness: self::readiness(67, context: $context));
            try {
                self::assess($target, self::batch([]));
                self::fail('Foreign readiness must not produce a numerical result.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testForeignScopeIsRejectedBeforeMetadataEvenForZeroAndFailedSources(): void
    {
        $context = self::context();
        foreach ([
            [self::target(quantity: 0, context: self::context(company: self::FOREIGN)), self::batch([])],
            [self::target(quantity: 0, context: self::context(account: self::FOREIGN)), self::batch([])],
            [self::target(quantity: 0), new NetTrainingBatch(self::FOREIGN, self::ACCOUNT, $context->readAt, null, [], [], 'source_history_unavailable')],
            [self::target(quantity: 0), new NetTrainingBatch(self::COMPANY, self::FOREIGN, $context->readAt, null, [], [], 'source_history_unavailable')],
            [self::target(), self::batch([], self::context(company: self::FOREIGN))],
            [self::target(), self::batch([], self::context(account: self::FOREIGN))],
        ] as [$target, $batch]) {
            try {
                self::assess($target, $batch);
                self::fail('Foreign scope must not yield metadata.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testContextFingerprintAndReadAtCannotBeBypassedByZeroOrFailedSource(): void
    {
        $context = self::context();
        $differentEpoch = new NetForecastCaptureEpoch('01900000-0000-7000-8000-000000000005', NetForecastCaptureEpoch::CAPTURE_VERSION, new \DateTimeImmutable('2026-01-01T00:00:00Z'), null, 'another-release');
        foreach ([
            self::batch([], self::context(snapshot: 'another-snapshot')),
            self::batch([], new NetTrainingContext(self::COMPANY, self::ACCOUNT, $context->readAt, $differentEpoch, $context->snapshotId)),
            new NetTrainingBatch(self::COMPANY, self::ACCOUNT, $context->readAt->modify('+1 second'), $context, [], [], 'source_history_unavailable'),
        ] as $batch) {
            foreach ([0, 80] as $quantity) {
                $result = self::assess(self::target(quantity: $quantity), $batch);
                self::assertSame('training_context_mismatch', $result->unavailableReason);
                self::assertNull($result->quantities);
                self::assertSame([], $result->attempts);
            }
        }
        foreach ([null, '', 'single_statement'] as $snapshot) {
            $target = self::target(context: self::context(snapshot: $snapshot));
            self::assertSame('target_snapshot_missing', self::assess($target, self::batch([]))->unavailableReason);
        }
    }

    private static function assess(DeliveredTargetEvidence $target, NetTrainingBatch $batch): DeliveredRiskAssessment
    {
        return (new AssessDeliveredRiskAction(new BuildReturnCurveAction()))->assess(self::COMPANY, self::ACCOUNT, $target, $batch);
    }

    private static function context(string $company = self::COMPANY, string $account = self::ACCOUNT, ?string $snapshot = '100:200:150'): NetTrainingContext
    {
        $epoch = new NetForecastCaptureEpoch('01900000-0000-7000-8000-000000000004', NetForecastCaptureEpoch::CAPTURE_VERSION, new \DateTimeImmutable('2026-01-01T00:00:00Z'), null, 'release-b02');

        return new NetTrainingContext($company, $account, new \DateTimeImmutable('2026-10-28T12:00:00.123456Z'), $epoch, $snapshot);
    }

    private static function target(?int $quantity = 80, int $age = 20, ?NetTrainingContext $context = null, bool $complete = true, ?string $reason = null, ?\DateTimeImmutable $origin = null, bool $unknownOrigin = false, ?DeliveredTargetReadiness $readiness = null): DeliveredTargetEvidence
    {
        $context ??= self::context();

        return new DeliveredTargetEvidence($context, self::ORDER, self::SKU, $quantity, $unknownOrigin ? null : ($origin ?? $context->readAt->modify(\sprintf('%+d days', -$age))), $complete, $reason, $readiness);
    }

    private static function readiness(int $age, int $throughDay = 67, ?int $deepDay = 67, int $fromDay = 0, bool $history = true, bool $postings = true, bool $returns = true, string $policy = NetTrainingContext::COVERAGE_POLICY, string $order = self::ORDER, string $sku = self::SKU, ?NetTrainingContext $context = null): DeliveredTargetReadiness
    {
        $context ??= self::context();
        $origin = $context->readAt->modify(\sprintf('-%d days', $age));

        return new DeliveredTargetReadiness($context, $order, $sku, $origin, $policy, $origin->modify(\sprintf('%+d days', $fromDay)), $origin->modify(\sprintf('%+d days', $throughDay)), null === $deepDay ? null : $origin->modify(\sprintf('%+d days', $deepDay)), $history, $postings, $returns);
    }

    /** @param list<ReturnTrainingAggregateRow> $rows */
    private static function batch(array $rows, ?NetTrainingContext $context = null): NetTrainingBatch
    {
        $context ??= self::context();

        return new NetTrainingBatch(self::COMPANY, self::ACCOUNT, $context->readAt, $context, [], $rows);
    }

    private static function row(?string $sku, int $quantity = 100, int $postings = 30, int $returned = 20, int $horizon = 30, int $bound = 20, bool $complete = true, ?string $reason = null): ReturnTrainingAggregateRow
    {
        $points = [];
        for ($age = 0; $age <= 60; ++$age) {
            $r = $age < 20 ? 0 : (60 === $age ? $horizon : $returned);
            $points[] = new ReturnTrainingAgeRow($age, $r, $quantity - $r, 0 === $r ? $postings : min($bound, $quantity - $r));
        }

        return new ReturnTrainingAggregateRow($sku, $complete, $reason, $quantity, $postings, $points, 7);
    }
}
