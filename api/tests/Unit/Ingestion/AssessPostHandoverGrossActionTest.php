<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Application\NetForecast\AssessPostHandoverGrossAction;
use App\Ingestion\Application\NetForecast\AssessPreHandoverGrossAction;
use App\Ingestion\Application\NetForecast\BuildPostHandoverGrossRateAction;
use App\Ingestion\Application\NetForecast\BuildPreHandoverGrossRateAction;
use App\Ingestion\Application\NetForecast\NetTrainingBatch;
use App\Ingestion\Application\NetForecast\OpenTargetEvidence;
use App\Ingestion\Application\NetForecast\PostHandoverGrossAssessment;
use App\Ingestion\Domain\ValueObject\GrossDeliveryQuantity;
use App\Ingestion\Domain\ValueObject\GrossProbability;
use App\Ingestion\Domain\ValueObject\NetForecastCaptureEpoch;
use App\Ingestion\Infrastructure\Query\NetForecast\GrossTrainingAggregateRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingBudgetFailure;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;
use PHPUnit\Framework\TestCase;

final class AssessPostHandoverGrossActionTest extends TestCase
{
    private const string COMPANY = '01900000-0000-7000-8000-000000000001';
    private const string ACCOUNT = '01900000-0000-7000-8000-000000000002';
    private const string FOREIGN = '01900000-0000-7000-8000-000000000003';
    private const string SKU = '00123';

    public function testSameRawSourceGivesHundredPostGrossAndFiftyPreGross(): void
    {
        $row = self::row();
        $batch = self::batch([$row, self::row(sku: null, gross: 200, t1: 0, postHandover: 60)]);
        $result = self::assess(self::target(), $batch);
        self::assertSame('sku', $result->basis);
        self::assertNull($result->unavailableReason);
        self::assertNotNull($result->probability);
        self::assertSame(100, $result->probability->numerator);
        self::assertSame(100, $result->probability->denominator);
        self::assertNotNull($result->quantity);
        self::assertSame('100', $result->quantity->expectedGrossNumerator);
        self::assertSame('1', $result->quantity->expectedGrossDenominator);
        self::assertCount(1, $result->attempts);
        self::assertSame($row, $result->attempts[0]->source);
        self::assertSame($batch->context, $result->attempts[0]->context);
        self::assertSame(self::COMPANY, $result->attempts[0]->companyId);
        self::assertSame(self::ACCOUNT, $result->attempts[0]->accountId);
        self::assertSame($batch->readAt, $result->attempts[0]->readAt);

        $pre = new AssessPreHandoverGrossAction(new BuildPreHandoverGrossRateAction())->assess(self::COMPANY, self::ACCOUNT, self::target(stage: OpenTargetEvidence::BEFORE_HANDOVER), $batch);
        self::assertNotNull($pre->probability);
        self::assertSame(100, $pre->probability->numerator);
        self::assertSame(200, $pre->probability->denominator);
        self::assertNotNull($pre->quantity);
        self::assertSame('50', $pre->quantity->expectedGrossNumerator);
    }

    public function testEveryRejectedSkuKeepsItsWholeDiagnosticsAndUsesWholeAccountFallback(): void
    {
        foreach ([
            [self::row(quantity: 1000, gross: 99, t1: 901), 'insufficient_sample'],
            [self::row(quantity: 1000, gross: 100, t1: 900, postHandover: 29), 'insufficient_sample'],
            [self::row(postHandover: null), 'gross_training_counts_missing'],
            [self::row(quantity: null), 'gross_training_counts_missing'],
            [self::row(postings: null), 'gross_training_counts_missing'],
            [self::row(gross: null), 'gross_training_counts_missing'],
            [self::row(t1: null), 'gross_training_counts_missing'],
            [self::row(t2: null), 'gross_training_counts_missing'],
            [self::row(p: null), 'gross_training_counts_missing'],
            [self::row(postHandover: -1), 'invalid_gross_training_counts'],
            [self::row(postHandover: 61), 'invalid_gross_training_counts'],
            [self::row(postHandover: 0), 'invalid_gross_training_counts'],
            [self::row(gross: 1, t1: 199, postHandover: 2), 'invalid_gross_training_counts'],
            [self::row(gross: 0, t1: 200, postHandover: 1), 'invalid_gross_training_counts'],
            [self::row(quantity: 100, gross: 100, t1: 0), 'invalid_gross_training_counts'],
            [self::row(gross: 201), 'invalid_gross_training_counts'],
            [self::row(gross: 99), 'invalid_gross_training_counts'],
            [self::row(gross: -1, t1: 201), 'invalid_gross_training_counts'],
            [self::row(quantity: \PHP_INT_MAX, gross: \PHP_INT_MAX, t1: \PHP_INT_MAX), 'invalid_gross_training_counts'],
            [self::row(p95: null), 'maturity_unavailable'],
            [self::row(p95: -1), 'invalid_gross_training_counts'],
            [self::row(excludedGroups: -1), 'invalid_gross_training_counts'],
            [self::row(excludedDays: -1), 'invalid_gross_training_counts'],
            [self::row(complete: false), 'gross_training_input_incomplete'],
            [self::row(reason: 'source_history_unavailable'), 'source_history_unavailable'],
            [self::row(budget: new NetTrainingBudgetFailure('outcomes', 101, 100)), 'input_budget_exceeded'],
        ] as [$sku, $reason]) {
            $account = self::row(sku: null, quantity: 400, gross: 100, t1: 100, t2: 150, p: 50, postHandover: 40, excludedGroups: 7, excludedDays: 8);
            $result = self::assess(self::target(), self::batch([$sku, $account]));
            self::assertSame('account', $result->basis);
            self::assertNotNull($result->probability);
            self::assertSame(100, $result->probability->numerator);
            self::assertSame(300, $result->probability->denominator);
            self::assertNotNull($result->quantity);
            self::assertSame('100', $result->quantity->expectedGrossNumerator);
            self::assertSame('3', $result->quantity->expectedGrossDenominator);
            self::assertCount(2, $result->attempts);
            self::assertSame($sku, $result->attempts[0]->source);
            self::assertSame($reason, $result->attempts[0]->unavailableReason);
            self::assertSame(self::SKU, $result->attempts[0]->marketplaceSku);
            self::assertSame($account, $result->attempts[1]->source);
            self::assertNull($result->attempts[1]->marketplaceSku);
            self::assertSame(7, $result->attempts[1]->source->excludedGroups);
            self::assertSame(8, $result->attempts[1]->source->excludedDays);
        }
    }

    public function testNoUsableTrainingNeverBecomesNumericZeroOrBorrowsAnotherSku(): void
    {
        foreach ([[], [self::row(sku: 'other-sku')],
            [self::row(gross: 0, t1: 200, postHandover: 0), self::row(sku: null, quantity: 0, postings: 0, gross: 0, t1: 0, postHandover: 0)],
            [self::row(postHandover: null), self::row(sku: null, postHandover: null)],
        ] as $rows) {
            $result = self::assess(self::target(), self::batch($rows));
            self::assertNull($result->quantity);
            self::assertNull($result->probability);
            self::assertNull($result->basis);
            self::assertSame('gross_probability_unavailable', $result->unavailableReason);
            self::assertCount(2, $result->attempts);
        }
    }

    public function testGenuineConditionalZeroAndOneAndP95ZeroAreAvailableWithOverlappingPostings(): void
    {
        foreach ([[0, 40, 60, '0'], [100, 0, 0, '100']] as [$gross, $t2, $p, $expected]) {
            $result = self::assess(self::target(), self::batch([self::row(postings: 30, gross: $gross, t2: $t2, p: $p, p95: 0)]));
            self::assertSame('sku', $result->basis);
            self::assertNotNull($result->quantity);
            self::assertNotNull($result->probability);
            self::assertSame($gross, $result->probability->numerator);
            self::assertSame(100, $result->probability->denominator);
            self::assertSame($expected, $result->quantity->expectedGrossNumerator);
        }
    }

    public function testTrustedZeroNeedsNoTrainingButWrongStageOrUnknownEvidenceCannotProduceZero(): void
    {
        $batch = self::batch([], noContext: true, reason: 'source_history_unavailable');
        $zero = self::assess(self::target(quantity: 0), $batch);
        self::assertSame('no_eligible_open', $zero->basis);
        self::assertNotNull($zero->quantity);
        self::assertSame('0', $zero->quantity->expectedGrossNumerator);
        self::assertSame('1', $zero->quantity->expectedGrossDenominator);
        self::assertNull($zero->probability);
        self::assertSame([], $zero->attempts);
        foreach ([
            [self::target(quantity: null), 'unknown_eligible_open_quantity'],
            [self::target(quantity: 0, complete: false), 'target_input_incomplete'],
            [self::target(quantity: 0, reason: 'contradictory_quantity'), 'contradictory_quantity'],
            [self::target(quantity: 0, stage: 'unknown'), 'unsupported_target_stage'],
            [self::target(quantity: 0, stage: OpenTargetEvidence::BEFORE_HANDOVER), 'unsupported_target_stage'],
            [self::target(stage: OpenTargetEvidence::BEFORE_HANDOVER), 'unsupported_target_stage'],
        ] as [$target, $reason]) {
            $result = self::assess($target, $batch);
            self::assertNull($result->quantity);
            self::assertSame($reason, $result->unavailableReason);
            self::assertSame([], $result->attempts);
        }
    }

    public function testMissingAndDuplicateRowsStayUnavailableInsteadOfMixingComponents(): void
    {
        $builder = new BuildPostHandoverGrossRateAction();
        foreach ([[[], self::SKU, 'gross_training_row_missing'],
            [[self::row(), self::row()], self::SKU, 'gross_training_row_ambiguous'],
            [[self::row(sku: null), self::row(sku: null)], null, 'gross_training_row_ambiguous'],
        ] as [$rows, $sku, $reason]) {
            $rate = $builder->build(self::COMPANY, self::ACCOUNT, self::batch($rows), $sku);
            self::assertNull($rate->probability);
            self::assertNull($rate->source);
            self::assertSame($reason, $rate->unavailableReason);
        }
    }

    public function testBuilderChecksContextReadAtAndBatchReadinessBeforeAnyRow(): void
    {
        $builder = new BuildPostHandoverGrossRateAction();
        $context = self::context();
        $invalidBatches = [[self::batch([self::row()], noContext: true), 'training_context_missing'],
            [self::batch([self::row()], reason: 'source_history_unavailable'), 'source_history_unavailable'],
            [new NetTrainingBatch(self::COMPANY, self::ACCOUNT, $context->readAt->modify('+1 microsecond'), $context, [self::row()], []), 'training_context_mismatch']];
        foreach ([null, '', ' ', 'single_statement'] as $snapshot) {
            $invalidBatches[] = [self::batch([self::row()], context: self::context(snapshot: $snapshot)), 'training_snapshot_missing'];
        }
        foreach ($invalidBatches as [$batch, $reason]) {
            $rate = $builder->build(self::COMPANY, self::ACCOUNT, $batch, self::SKU);
            self::assertSame($reason, $rate->unavailableReason);
            self::assertNull($rate->probability);
            self::assertNull($rate->source);
        }
    }

    public function testTargetSnapshotAndWholeContextMismatchPrecedeZeroShortcut(): void
    {
        foreach ([null, '', ' ', 'single_statement'] as $snapshot) {
            $result = self::assess(self::target(quantity: 0, context: self::context(snapshot: $snapshot)), self::batch([], noContext: true));
            self::assertSame('target_snapshot_missing', $result->unavailableReason);
            self::assertNull($result->quantity);
        }
        foreach ([self::context(snapshot: 'other-rr'), self::context(snapshot: null), self::context(snapshot: 'single_statement'),
            self::context(release: 'other'), self::context(readAt: '2026-10-28T00:00:00.000001Z'),
            self::context(epochId: self::FOREIGN), self::context(epochStart: '2026-01-02T00:00:00Z'),
            self::context(epochEnd: '2026-11-01T00:00:00Z'),
        ] as $context) {
            $result = self::assess(self::target(quantity: 0), self::batch([], context: $context));
            self::assertSame('training_context_mismatch', $result->unavailableReason);
            self::assertNull($result->quantity);
            self::assertSame([], $result->attempts);
        }
        $context = self::context();
        $batch = new NetTrainingBatch(self::COMPANY, self::ACCOUNT, $context->readAt->modify('+1 microsecond'), null, [], []);
        self::assertSame('training_context_mismatch', self::assess(self::target(quantity: 0), $batch)->unavailableReason);
        self::assertSame('training_context_missing', self::assess(self::target(), self::batch([], noContext: true))->unavailableReason);
    }

    public function testForeignTargetBatchAndPresentContextAreRejectedBeforeZeroAndPrivateReasons(): void
    {
        $context = self::context();
        $foreignCompany = self::context(company: self::FOREIGN);
        $foreignAccount = self::context(account: self::FOREIGN);
        foreach ([
            [self::target(quantity: 0, context: $foreignCompany), self::batch([])],
            [self::target(quantity: 0, context: $foreignAccount), self::batch([])],
            [self::target(quantity: 0), new NetTrainingBatch(self::FOREIGN, self::ACCOUNT, $context->readAt, null, [], [], 'private')],
            [self::target(quantity: 0), new NetTrainingBatch(self::COMPANY, self::FOREIGN, $context->readAt, null, [], [], 'private')],
            [self::target(quantity: 0), self::batch([], context: $foreignCompany, reason: 'private')],
            [self::target(quantity: 0), self::batch([], context: $foreignAccount, reason: 'private')],
        ] as [$target, $batch]) {
            try {
                self::assess($target, $batch);
                self::fail('Foreign evidence accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $builder = new BuildPostHandoverGrossRateAction();
        foreach ([
            new NetTrainingBatch(self::FOREIGN, self::ACCOUNT, $context->readAt, null, [], [], 'private'),
            new NetTrainingBatch(self::COMPANY, self::FOREIGN, $context->readAt, null, [], [], 'private'),
            self::batch([], context: $foreignCompany, reason: 'private'), self::batch([], context: $foreignAccount, reason: 'private'),
        ] as $batch) {
            try {
                $builder->build(self::COMPANY, self::ACCOUNT, $batch, self::SKU);
                self::fail('Foreign training accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAssessmentFactoriesRejectContradictoryAvailabilityAndEmptyReason(): void
    {
        $probability = new GrossProbability(1, 2);
        $quantity = GrossDeliveryQuantity::fromProbability(100, $probability);
        foreach ([[$quantity, $probability, 'other'], [$quantity, null, 'sku'],
            [$quantity, null, 'account'], [$quantity, null, 'no_eligible_open'],
            [GrossDeliveryQuantity::zero(), $probability, 'no_eligible_open'],
        ] as [$candidate, $rate, $basis]) {
            try {
                PostHandoverGrossAssessment::available($candidate, $rate, $basis, []);
                self::fail('Contradictory result accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        foreach (['', ' '] as $reason) {
            try {
                PostHandoverGrossAssessment::unavailable($reason);
                self::fail('Empty refusal accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @param list<GrossTrainingAggregateRow> $rows */
    private static function batch(array $rows, ?NetTrainingContext $context = null, bool $noContext = false, ?string $reason = null): NetTrainingBatch
    {
        $context ??= self::context();

        return new NetTrainingBatch(self::COMPANY, self::ACCOUNT, $context->readAt, $noContext ? null : $context, $rows, [], $reason);
    }

    private static function context(?string $snapshot = 'rr-b06', string $company = self::COMPANY, string $account = self::ACCOUNT, string $release = 'capture-release', string $readAt = '2026-10-28T00:00:00Z', string $epochId = '01900000-0000-7000-8000-000000000004', string $epochStart = '2026-01-01T00:00:00Z', ?string $epochEnd = null): NetTrainingContext
    {
        return new NetTrainingContext($company, $account, new \DateTimeImmutable($readAt), new NetForecastCaptureEpoch($epochId, NetForecastCaptureEpoch::CAPTURE_VERSION, new \DateTimeImmutable($epochStart), null === $epochEnd ? null : new \DateTimeImmutable($epochEnd), $release), $snapshot);
    }

    private static function target(?int $quantity = 100, bool $complete = true, ?string $reason = null, string $stage = 'after_handover', ?NetTrainingContext $context = null): OpenTargetEvidence
    {
        return new OpenTargetEvidence($context ?? self::context(), 'order-1', self::SKU, $stage, $quantity, $complete, $reason);
    }

    private static function row(?string $sku = self::SKU, ?int $quantity = 200, ?int $postings = 60, ?int $gross = 100, ?int $t1 = 100, ?int $t2 = 0, ?int $p = 0, ?int $p95 = 86400, bool $complete = true, ?string $reason = null, int $excludedGroups = 3, int $excludedDays = 2, ?NetTrainingBudgetFailure $budget = null, ?int $postHandover = 30): GrossTrainingAggregateRow
    {
        return new GrossTrainingAggregateRow($sku, $complete, $reason, $quantity, $postings, $gross, $t1, $t2, $p, $postHandover, $p95, $excludedGroups, $excludedDays, $budget);
    }

    private static function assess(OpenTargetEvidence $target, NetTrainingBatch $batch): PostHandoverGrossAssessment
    {
        return new AssessPostHandoverGrossAction(new BuildPostHandoverGrossRateAction())->assess(self::COMPANY, self::ACCOUNT, $target, $batch);
    }
}
