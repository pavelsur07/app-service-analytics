<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Application\NetForecast\NetQualityDecision;
use App\Tests\Support\Builder\NetQualityDecisionInputBuilder as Decision;
use PHPUnit\Framework\TestCase;

final class NetQualityDecisionTest extends TestCase
{
    public function testEqualityAtExactMinimumsPassesButDoesNotOpenRollout(): void
    {
        $result = (new NetQualityDecision())->decide(Decision::aDecision()->build());
        self::assertSame('QUALITY_PASSED', $result->decision);
        self::assertFalse($result->numericRolloutAllowed);
    }

    public function testNinetyNineCasesAndTwentyNineInAnyGroupAreInsufficient(): void
    {
        $decision = new NetQualityDecision();
        self::assertSame('INSUFFICIENT_DATA', $decision->decide(Decision::aDecision()->withCount('overall', 99)->build())->decision);
        self::assertSame('INSUFFICIENT_DATA', $decision->decide(Decision::aDecision()->withCount('delivery30_59', 29)->build())->decision);
        self::assertSame('INSUFFICIENT_DATA', $decision->decide(Decision::aDecision()->withWeeks('order6_9', ['2027-W01'])->build())->decision);
    }

    public function testTinyRegressionFailsEvenWhenSixDecimalDisplayIsZero(): void
    {
        $input = Decision::aDecision()->withTinyRegression('order3_5')->build();
        $display = $input->groups['order3_5']->metrics->method['display'];
        self::assertIsArray($display);
        self::assertSame('0.000000', $display['mae']);
        self::assertSame('QUALITY_FAILED', (new NetQualityDecision())->decide($input)->decision);
    }

    public function testIntegrityAndInsufficiencyTakePriorityButKnownFailuresRemainVisible(): void
    {
        $decision = new NetQualityDecision();
        $input = Decision::aDecision()->withCount('overall', 99)->withTinyRegression('order3_5')->build();
        $result = $decision->decide($input);
        self::assertSame('INSUFFICIENT_DATA', $result->decision);
        self::assertContains('order3_5.mae', $result->failedMetrics);
        self::assertSame('INVALID_EVIDENCE', $decision->decide(Decision::aDecision()->withCount('overall', 99)->withViolation('holdout_target_leakage')->build())->decision);
    }
}
