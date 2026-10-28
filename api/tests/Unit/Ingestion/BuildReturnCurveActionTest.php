<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Application\NetForecast\BuildReturnCurveAction;
use App\Ingestion\Application\NetForecast\NetTrainingBatch;
use App\Ingestion\Domain\ValueObject\NetForecastCaptureEpoch;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;
use App\Ingestion\Infrastructure\Query\NetForecast\ReturnTrainingAgeRow;
use App\Ingestion\Infrastructure\Query\NetForecast\ReturnTrainingAggregateRow;
use PHPUnit\Framework\TestCase;

final class BuildReturnCurveActionTest extends TestCase
{
    private const string COMPANY = '01900000-0000-7000-8000-000000000001';
    private const string ACCOUNT = '01900000-0000-7000-8000-000000000002';
    private const string FOREIGN = '01900000-0000-7000-8000-000000000003';

    public function testSelectedWholeRowRetainsScopeCountsAndCaptureMetadata(): void
    {
        $account = self::row(null, 200, 40);
        $sku = self::row('00123', 100, 30);
        $batch = self::batch([$account, $sku]);
        $result = (new BuildReturnCurveAction())->build(self::COMPANY, self::ACCOUNT, $batch, '00123');
        self::assertSame(self::COMPANY, $result->companyId);
        self::assertSame(self::ACCOUNT, $result->accountId);
        self::assertSame('00123', $result->marketplaceSku);
        self::assertSame($batch->context, $result->context);
        self::assertSame($batch->readAt, $result->readAt);
        self::assertSame($sku, $result->source);
        self::assertSame(7, $result->source->excludedGroups);
        self::assertNotNull($result->curve);
        $q = $result->curve->remainingAt(20)->probability;
        self::assertNotNull($q);
        self::assertSame(10, $q->numerator);
        self::assertSame(80, $q->denominator);
        $accountResult = (new BuildReturnCurveAction())->build(self::COMPANY, self::ACCOUNT, $batch);
        self::assertSame($account, $accountResult->source);
        self::assertNotNull($accountResult->curve);
        self::assertSame(180, $accountResult->curve->remainingAt(20)->probability?->denominator);
    }

    public function testMissingOrAmbiguousRequestedRowNeverFallsBackToAccount(): void
    {
        foreach ([[self::row(null)], [self::row('sku'), self::row('sku')]] as $rows) {
            $result = (new BuildReturnCurveAction())->build(self::COMPANY, self::ACCOUNT, self::batch($rows), 'sku');
            self::assertNull($result->curve);
            self::assertNull($result->source);
            self::assertSame(1 === \count($rows) ? 'return_training_row_missing' : 'return_training_row_ambiguous', $result->unavailableReason);
        }
    }

    public function testForeignBatchAndContextAreRejectedBeforeReturningMetadata(): void
    {
        $batch = self::batch([self::row(null)]);
        $context = $batch->context;
        self::assertNotNull($context);
        foreach ([
            [self::FOREIGN, self::ACCOUNT, $batch],
            [self::COMPANY, self::FOREIGN, $batch],
            [self::COMPANY, self::ACCOUNT, new NetTrainingBatch(self::COMPANY, self::ACCOUNT, $batch->readAt, new NetTrainingContext(self::FOREIGN, self::ACCOUNT, $batch->readAt, $context->epoch), [], [self::row(null)])],
            [self::COMPANY, self::ACCOUNT, new NetTrainingBatch(self::COMPANY, self::ACCOUNT, $batch->readAt, new NetTrainingContext(self::COMPANY, self::FOREIGN, $batch->readAt, $context->epoch), [], [self::row(null)])],
        ] as [$company, $account, $input]) {
            try {
                (new BuildReturnCurveAction())->build($company, $account, $input);
                self::fail('Foreign input must not yield a curve or metadata.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testGlobalAndContextReadinessCannotBeBypassedByValidRows(): void
    {
        $batch = self::batch([self::row(null)]);
        $context = $batch->context;
        self::assertNotNull($context);
        foreach ([
            [new NetTrainingBatch(self::COMPANY, self::ACCOUNT, $batch->readAt, $context, [], $batch->returns, 'source_history_unavailable'), 'source_history_unavailable'],
            [new NetTrainingBatch(self::COMPANY, self::ACCOUNT, $batch->readAt, null, [], $batch->returns), 'training_context_missing'],
            [new NetTrainingBatch(self::COMPANY, self::ACCOUNT, $batch->readAt->modify('+1 second'), $context, [], $batch->returns), 'training_context_mismatch'],
        ] as [$input, $reason]) {
            $result = (new BuildReturnCurveAction())->build(self::COMPANY, self::ACCOUNT, $input);
            self::assertNull($result->curve);
            self::assertSame($reason, $result->unavailableReason);
        }
    }

    public function testSourceFailuresMissingInputsAndUnsupportedProofAreExplicit(): void
    {
        $valid = self::row(null);
        foreach ([
            [new ReturnTrainingAggregateRow(null, false, 'returns_coverage_missing', null, null, null, 4), 'returns_coverage_missing'],
            [new ReturnTrainingAggregateRow(null, false, null, 100, 30, $valid->ageProfile, 4), 'return_training_input_incomplete'],
            [new ReturnTrainingAggregateRow(null, true, 'unknown_outcome', 100, 30, $valid->ageProfile, 4), 'unknown_outcome'],
            [new ReturnTrainingAggregateRow(null, true, null, 100, 30, null, 4), 'return_training_profile_missing'],
            [new ReturnTrainingAggregateRow(null, true, null, null, 30, $valid->ageProfile, 4), 'return_training_profile_missing'],
            [new ReturnTrainingAggregateRow(null, true, null, 100, null, $valid->ageProfile, 4), 'return_training_profile_missing'],
            [new ReturnTrainingAggregateRow(null, true, null, 100, 30, $valid->ageProfile, 4, 'exact_postings_v0'), 'unsupported_risk_posting_method'],
        ] as [$row, $reason]) {
            $result = (new BuildReturnCurveAction())->build(self::COMPANY, self::ACCOUNT, self::batch([$row]));
            self::assertSame($row, $result->source);
            self::assertNull($result->curve);
            self::assertSame($reason, $result->unavailableReason);
        }
    }

    public function testInvalidDomainCountsFailClosedAndSmallSampleNeverBecomesZero(): void
    {
        $valid = self::row(null);
        self::assertNotNull($valid->ageProfile);
        $badProfile = $valid->ageProfile;
        $badProfile[20] = new ReturnTrainingAgeRow(20, 20, 79, 20);
        foreach ([
            new ReturnTrainingAggregateRow(null, true, null, -1, 30, $valid->ageProfile, 4),
            new ReturnTrainingAggregateRow(null, true, null, 100, 30, array_values($badProfile), 4),
            new ReturnTrainingAggregateRow(null, true, null, 100, 30, [], 4),
            new ReturnTrainingAggregateRow(null, true, null, 100, 30, [new ReturnTrainingAgeRow(-1, 0, 100, 30)], 4),
        ] as $row) {
            $result = (new BuildReturnCurveAction())->build(self::COMPANY, self::ACCOUNT, self::batch([$row]));
            self::assertNull($result->curve);
            self::assertSame('invalid_return_training_profile', $result->unavailableReason);
        }
        $result = (new BuildReturnCurveAction())->build(self::COMPANY, self::ACCOUNT, self::batch([self::row(null, 99, 30)]));
        self::assertNotNull($result->curve);
        self::assertNull($result->curve->remainingAt(20)->probability);
        self::assertSame('insufficient_sample', $result->curve->remainingAt(20)->unavailableReason);
    }

    /** @param list<ReturnTrainingAggregateRow> $rows */
    private static function batch(array $rows): NetTrainingBatch
    {
        $readAt = new \DateTimeImmutable('2026-10-28T12:00:00Z');
        $epoch = new NetForecastCaptureEpoch('01900000-0000-7000-8000-000000000004', NetForecastCaptureEpoch::CAPTURE_VERSION, new \DateTimeImmutable('2026-01-01T00:00:00Z'), null, 'release-b02');
        $context = new NetTrainingContext(self::COMPANY, self::ACCOUNT, $readAt, $epoch, '100:200:150');

        return new NetTrainingBatch(self::COMPANY, self::ACCOUNT, $readAt, $context, [], $rows);
    }

    private static function row(?string $sku, int $quantity = 100, int $postings = 30): ReturnTrainingAggregateRow
    {
        $profile = [];
        for ($age = 0; $age <= 60; ++$age) {
            $returned = $age < 20 ? 0 : ($age < 40 ? 20 : 30);
            $profile[] = new ReturnTrainingAgeRow($age, $returned, $quantity - $returned, 0 === $returned ? $postings : 20);
        }

        return new ReturnTrainingAggregateRow($sku, true, null, $quantity, $postings, $profile, 7);
    }
}
