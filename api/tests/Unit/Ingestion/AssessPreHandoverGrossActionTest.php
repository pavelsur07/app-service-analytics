<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Application\NetForecast\AssessPreHandoverGrossAction;
use App\Ingestion\Application\NetForecast\BuildPreHandoverGrossRateAction;
use App\Ingestion\Application\NetForecast\NetTrainingBatch;
use App\Ingestion\Application\NetForecast\OpenTargetEvidence;
use App\Ingestion\Application\NetForecast\PreHandoverGrossAssessment;
use App\Ingestion\Domain\ValueObject\NetForecastCaptureEpoch;
use App\Ingestion\Infrastructure\Query\NetForecast\GrossTrainingAggregateRow;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingBudgetFailure;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;
use PHPUnit\Framework\TestCase;

final class AssessPreHandoverGrossActionTest extends TestCase
{
    private const string COMPANY = '01900000-0000-7000-8000-000000000001';
    private const string ACCOUNT = '01900000-0000-7000-8000-000000000002';
    private const string FOREIGN = '01900000-0000-7000-8000-000000000003';
    private const string SKU = '00123';

    public function testHundredGrossAndHundredT1GiveFiftyExpectedFromHundredEligible(): void
    {
        $row = self::row();
        $batch = self::batch([$row, self::row(sku: null, gross: 200, t1: 0)]);
        $result = self::assess(self::target(), $batch);
        self::assertSame('sku', $result->basis);
        self::assertNotNull($result->probability);
        self::assertSame(100, $result->probability->numerator);
        self::assertSame(200, $result->probability->denominator);
        self::assertNotNull($result->quantity);
        self::assertSame('50', $result->quantity->expectedGrossNumerator);
        self::assertSame('1', $result->quantity->expectedGrossDenominator);
        self::assertCount(1, $result->attempts);
        self::assertSame($row, $result->attempts[0]->source);
        self::assertSame($batch->context, $result->attempts[0]->context);
    }

    public function testFallbackPreservesWholeSourceAndFailedSampleDiagnostics(): void
    {
        foreach ([
            [self::row(quantity: 99, gross: 49, t1: 50), 'insufficient_sample'],
            [self::row(postings: 29), 'insufficient_sample'],
            [self::row(p95: null), 'maturity_unavailable'],
            [self::row(p95: -1), 'invalid_gross_training_counts'],
            [self::row(complete: false), 'gross_training_input_incomplete'],
            [self::row(reason: 'source_history_unavailable'), 'source_history_unavailable'],
            [self::row(gross: null), 'gross_training_counts_missing'],
            [self::row(t1: null), 'gross_training_counts_missing'],
            [self::row(t2: null), 'gross_training_counts_missing'],
            [self::row(p: null), 'gross_training_counts_missing'],
            [self::row(quantity: null), 'gross_training_counts_missing'],
            [self::row(postings: null), 'gross_training_counts_missing'],
            [self::row(gross: 201), 'invalid_gross_training_counts'],
            [self::row(excluded: -1), 'invalid_gross_training_counts'],
            [self::row(budget: new NetTrainingBudgetFailure('outcomes', 101, 100)), 'input_budget_exceeded'],
        ] as [$sku, $reason]) {
            $account = self::row(sku: null, quantity: 300, gross: 100, t1: 200, excluded: 7);
            $result = self::assess(self::target(), self::batch([$sku, $account]));
            self::assertSame('account', $result->basis);
            self::assertNotNull($result->probability);
            self::assertSame(100, $result->probability->numerator);
            self::assertSame(300, $result->probability->denominator);
            self::assertNotNull($result->quantity);
            self::assertSame('100', $result->quantity->expectedGrossNumerator);
            self::assertSame('3', $result->quantity->expectedGrossDenominator);
            self::assertSame($sku, $result->attempts[0]->source);
            self::assertSame($reason, $result->attempts[0]->unavailableReason);
            self::assertSame($account, $result->attempts[1]->source);
            self::assertSame(7, $result->attempts[1]->source->excludedGroups);
        }
    }

    public function testKnownZeroNeedsNoTrainingOrFakeProbabilityButUnknownNeverBecomesZero(): void
    {
        $batch = self::batch([], noContext: true, reason: 'source_history_unavailable');
        $zero = self::assess(self::target(quantity: 0), $batch);
        self::assertSame('no_eligible_open', $zero->basis);
        self::assertNotNull($zero->quantity);
        self::assertSame('0', $zero->quantity->expectedGrossNumerator);
        self::assertNull($zero->probability);
        self::assertSame([], $zero->attempts);
        foreach ([[self::target(quantity: null), 'unknown_eligible_open_quantity'],
            [self::target(quantity: 0, complete: false), 'target_input_incomplete'],
            [self::target(quantity: 0, reason: 'contradictory_quantity'), 'contradictory_quantity'],
            [self::target(quantity: 0, stage: 'after_handover'), 'unsupported_target_stage']] as [$target, $reason]) {
            $result = self::assess($target, $batch);
            self::assertNull($result->quantity);
            self::assertSame($reason, $result->unavailableReason);
        }
    }

    public function testEmpiricalZeroAndOneNeedRealSufficientSourceAndP95ZeroIsAllowed(): void
    {
        foreach ([[0, 100, '0'], [100, 0, '100']] as [$gross, $t1, $expected]) {
            $result = self::assess(self::target(), self::batch([self::row(quantity: 100, gross: $gross, t1: $t1, p95: 0, postHandover: null)]));
            self::assertNotNull($result->quantity);
            self::assertNotNull($result->probability);
            self::assertSame($expected, $result->quantity->expectedGrossNumerator);
        }
        $empty = self::assess(self::target(), self::batch([self::row(quantity: 0, postings: 0, gross: 0, t1: 0), self::row(sku: null, quantity: 0, postings: 0, gross: 0, t1: 0)]));
        self::assertNull($empty->quantity);
        self::assertSame('gross_probability_unavailable', $empty->unavailableReason);
    }

    public function testMissingAndDuplicateRowsNeverMixComponentsOrBorrowOtherSku(): void
    {
        $builder = new BuildPreHandoverGrossRateAction();
        foreach ([[[], 'gross_training_row_missing'], [[self::row(), self::row()], 'gross_training_row_ambiguous']] as [$rows, $reason]) {
            $rate = $builder->build(self::COMPANY, self::ACCOUNT, self::batch($rows), self::SKU);
            self::assertNull($rate->probability);
            self::assertNull($rate->source);
            self::assertSame($reason, $rate->unavailableReason);
        }
        $result = self::assess(self::target(), self::batch([self::row(sku: 'other-sku')]));
        self::assertNull($result->quantity);
        self::assertCount(2, $result->attempts);
    }

    public function testContextAndSnapshotChecksPrecedeZeroAndMetadataShortcuts(): void
    {
        foreach ([null, '', ' ', 'single_statement'] as $snapshot) {
            $result = self::assess(self::target(quantity: 0, context: self::context(snapshot: $snapshot)), self::batch([], noContext: true));
            self::assertSame('target_snapshot_missing', $result->unavailableReason);
            self::assertNull($result->quantity);
        }
        foreach ([self::context(snapshot: 'other'), self::context(release: 'other'), self::context(readAt: '2026-10-28T00:00:00.000001Z')] as $context) {
            $result = self::assess(self::target(quantity: 0), self::batch([], context: $context));
            self::assertSame('training_context_mismatch', $result->unavailableReason);
            self::assertNull($result->quantity);
        }
        $context = self::context();
        $batch = new NetTrainingBatch(self::COMPANY, self::ACCOUNT, $context->readAt->modify('+1 second'), $context, [], []);
        self::assertSame('training_context_mismatch', self::assess(self::target(quantity: 0), $batch)->unavailableReason);
        self::assertSame('training_context_missing', self::assess(self::target(), self::batch([], noContext: true))->unavailableReason);
        $builder = new BuildPreHandoverGrossRateAction();
        foreach ([self::batch([], noContext: true), self::batch([], context: self::context(snapshot: 'single_statement')), $batch] as $invalid) {
            self::assertNull($builder->build(self::COMPANY, self::ACCOUNT, $invalid, self::SKU)->probability);
        }
    }

    public function testForeignTargetBatchAndPresentContextAreRejectedBeforeZeroOrReasons(): void
    {
        $context = self::context();
        $foreign = self::context(company: self::FOREIGN);
        $foreignAccount = self::context(account: self::FOREIGN);
        foreach ([
            [self::target(quantity: 0, context: $foreign), self::batch([])],
            [self::target(quantity: 0, context: $foreignAccount), self::batch([])],
            [self::target(quantity: 0), new NetTrainingBatch(self::FOREIGN, self::ACCOUNT, $context->readAt, null, [], [], 'secret')],
            [self::target(quantity: 0), new NetTrainingBatch(self::COMPANY, self::FOREIGN, $context->readAt, null, [], [], 'secret')],
            [self::target(quantity: 0), self::batch([], context: $foreign, reason: 'secret')],
            [self::target(quantity: 0), self::batch([], context: $foreignAccount, reason: 'secret')],
        ] as [$target, $batch]) {
            try {
                self::assess($target, $batch);
                self::fail('Foreign evidence accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new BuildPreHandoverGrossRateAction()->build(self::COMPANY, self::ACCOUNT, self::batch([], context: $foreign), self::SKU);
    }

    public function testInvalidTargetCannotManufactureKnownQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::target(quantity: -1);
    }

    /** @param list<GrossTrainingAggregateRow> $rows */
    private static function batch(array $rows, ?NetTrainingContext $context = null, bool $noContext = false, ?string $reason = null): NetTrainingBatch
    {
        $context ??= self::context();

        return new NetTrainingBatch(self::COMPANY, self::ACCOUNT, $context->readAt, $noContext ? null : $context, $rows, [], $reason);
    }

    private static function context(?string $snapshot = 'rr-b05', string $company = self::COMPANY, string $account = self::ACCOUNT, string $release = 'capture-release', string $readAt = '2026-10-28T00:00:00Z'): NetTrainingContext
    {
        return new NetTrainingContext($company, $account, new \DateTimeImmutable($readAt), new NetForecastCaptureEpoch('01900000-0000-7000-8000-000000000004', NetForecastCaptureEpoch::CAPTURE_VERSION, new \DateTimeImmutable('2026-01-01T00:00:00Z'), null, $release), $snapshot);
    }

    private static function target(?int $quantity = 100, bool $complete = true, ?string $reason = null, string $stage = 'before_handover', ?NetTrainingContext $context = null): OpenTargetEvidence
    {
        return new OpenTargetEvidence($context ?? self::context(), 'order-1', self::SKU, $stage, $quantity, $complete, $reason);
    }

    private static function row(?string $sku = self::SKU, ?int $quantity = 200, ?int $postings = 30, ?int $gross = 100, ?int $t1 = 100, ?int $t2 = 0, ?int $p = 0, ?int $p95 = 86400, bool $complete = true, ?string $reason = null, int $excluded = 3, ?NetTrainingBudgetFailure $budget = null, ?int $postHandover = 30): GrossTrainingAggregateRow
    {
        return new GrossTrainingAggregateRow($sku, $complete, $reason, $quantity, $postings, $gross, $t1, $t2, $p, $postHandover, $p95, $excluded, 2, $budget);
    }

    private static function assess(OpenTargetEvidence $target, NetTrainingBatch $batch): PreHandoverGrossAssessment
    {
        return new AssessPreHandoverGrossAction(new BuildPreHandoverGrossRateAction())->assess(self::COMPANY, self::ACCOUNT, $target, $batch);
    }
}
