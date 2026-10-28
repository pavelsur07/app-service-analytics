<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Domain\ValueObject\ObservedReturnCurve;
use App\Ingestion\Domain\ValueObject\ReturnCurvePoint;
use App\Ingestion\Domain\ValueObject\ReturnProbability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ObservedReturnCurveTest extends TestCase
{
    public function testConditionalRiskUsesOnlyRemainingUnitsAndPreservesExactComponents(): void
    {
        $curve = new ObservedReturnCurve(100, 30, self::points());
        $this->assertFraction(20, 100, $curve->cumulativeAt(20)->probability);
        $this->assertFraction(30, 100, $curve->cumulativeAt(60)->probability);
        $this->assertFraction(10, 80, $curve->remainingAt(20)->probability);
        $this->assertFraction(30, 100, $curve->remainingAt(0)->probability);
        $this->assertFraction(0, 70, $curve->remainingAt(59)->probability);
    }

    public function testSampleAndRiskThresholdsAreInclusiveAndIndependent(): void
    {
        foreach ([[99, 30], [100, 29], [0, 0]] as [$quantity, $postings]) {
            $curve = new ObservedReturnCurve($quantity, $postings, self::points($quantity, $postings, 0, 0));
            self::assertSame('insufficient_sample', $curve->cumulativeAt(60)->unavailableReason);
            self::assertNull($curve->cumulativeAt(60)->probability);
            self::assertNull($curve->remainingAt(0)->probability);
        }
        foreach ([[70, 10, true], [71, 10, false], [70, 9, false]] as [$returned, $bound, $available]) {
            $curve = new ObservedReturnCurve(100, 30, self::points(100, 30, $returned, $returned, $bound));
            self::assertNotNull($curve->cumulativeAt(60)->probability);
            if ($available) {
                $this->assertFraction(0, 30, $curve->remainingAt(20)->probability);
            } else {
                self::assertSame('insufficient_risk_set', $curve->remainingAt(20)->unavailableReason);
                self::assertNull($curve->remainingAt(20)->probability);
            }
        }
    }

    public function testAllReturnedAndNoFutureReturnsHaveDifferentZeroRiskSemantics(): void
    {
        $all = new ObservedReturnCurve(100, 30, self::points(100, 30, 20, 100));
        $this->assertFraction(80, 80, $all->remainingAt(20)->probability);
        self::assertNull($all->remainingAt(59)->probability);
        self::assertSame('insufficient_risk_set', $all->remainingAt(59)->unavailableReason);
        $none = new ObservedReturnCurve(100, 30, self::points(100, 30, 0, 0));
        $this->assertFraction(0, 100, $none->remainingAt(20)->probability);
    }

    public function testCurveCumulativeIncreasesWhileConditionalRiskDecreases(): void
    {
        $curve = new ObservedReturnCurve(100, 30, self::points());
        $previousR = 0;
        $previousQ = 30;
        $previousD = 100;
        for ($age = 0; $age < 60; ++$age) {
            $f = $curve->cumulativeAt($age)->probability;
            $q = $curve->remainingAt($age)->probability;
            self::assertNotNull($f);
            self::assertNotNull($q);
            self::assertGreaterThanOrEqual($previousR, $f->numerator);
            self::assertLessThanOrEqual($previousQ * $q->denominator, $q->numerator * $previousD);
            self::assertGreaterThanOrEqual(0, $q->numerator);
            self::assertLessThanOrEqual($q->denominator, $q->numerator);
            $previousR = $f->numerator;
            $previousQ = $q->numerator;
            $previousD = $q->denominator;
        }
    }

    public function testLargeCountsNeverRequireProductsOrFloatConversion(): void
    {
        $curve = new ObservedReturnCurve(\PHP_INT_MAX, 30, self::points(\PHP_INT_MAX));
        $this->assertFraction(10, \PHP_INT_MAX - 20, $curve->remainingAt(20)->probability);
        $this->assertFraction(30, \PHP_INT_MAX, $curve->cumulativeAt(60)->probability);
    }

    #[DataProvider('invalidProfiles')]
    public function testImpossibleProfilesAreRejectedWithoutClipping(string $mutation): void
    {
        $points = self::points();
        match ($mutation) {
            'missing' => array_pop($points),
            'duplicate' => $points[20] = $points[19],
            'unordered' => [$points[19], $points[20]] = [$points[20], $points[19]],
            'nonmonotone_return' => $points[21] = new ReturnCurvePoint(21, 19, 81, 20),
            'wrong_risk' => $points[20] = new ReturnCurvePoint(20, 20, 79, 20),
            'too_many_returns' => $points[60] = new ReturnCurvePoint(60, 101, 0, 0),
            'nonzero_origin' => $points[0] = new ReturnCurvePoint(0, 1, 99, 30),
            'inexact_origin_postings' => $points[0] = new ReturnCurvePoint(0, 0, 100, 29),
            'increasing_posting_bound' => $points[21] = new ReturnCurvePoint(21, 20, 80, 21),
            'too_many_risk_postings' => $points[20] = new ReturnCurvePoint(20, 20, 80, 31),
            'postings_exceed_quantity' => $points[60] = new ReturnCurvePoint(60, 95, 5, 6),
            'unproved_positive_risk' => $points[60] = new ReturnCurvePoint(60, 30, 70, 0),
            'postings_without_risk' => $points[60] = new ReturnCurvePoint(60, 100, 0, 1),
            default => throw new \LogicException('Unknown profile mutation.'),
        };
        $this->expectException(\InvalidArgumentException::class);
        new ObservedReturnCurve(100, 30, array_values($points));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidProfiles(): iterable
    {
        foreach (['missing', 'duplicate', 'unordered', 'nonmonotone_return', 'wrong_risk', 'too_many_returns', 'nonzero_origin', 'inexact_origin_postings', 'increasing_posting_bound', 'too_many_risk_postings', 'postings_exceed_quantity', 'unproved_positive_risk', 'postings_without_risk'] as $mutation) {
            yield $mutation => [$mutation];
        }
    }

    public function testNegativeCountsImpossibleSamplesAndInvalidAgesAreRejected(): void
    {
        $invalid = [
            static fn () => new ReturnCurvePoint(-1, 0, 100, 30),
            static fn () => new ReturnCurvePoint(61, 0, 100, 30),
            static fn () => new ReturnCurvePoint(0, -1, 100, 30),
            static fn () => new ReturnCurvePoint(0, 0, -1, 30),
            static fn () => new ReturnCurvePoint(0, 0, 100, -1),
            static fn () => new ObservedReturnCurve(-1, 30, []),
            static fn () => new ObservedReturnCurve(100, -1, []),
            static fn () => new ObservedReturnCurve(100, 101, []),
            static fn () => new ObservedReturnCurve(100, 0, self::points(100, 0)),
            static fn () => new ReturnProbability(1, 0),
            static fn () => new ReturnProbability(-1, 100),
            static fn () => new ReturnProbability(101, 100),
            static fn () => (new ObservedReturnCurve(100, 30, self::points()))->remainingAt(60),
            static fn () => (new ObservedReturnCurve(100, 30, self::points()))->remainingAt(-1),
            static fn () => (new ObservedReturnCurve(100, 30, self::points()))->cumulativeAt(61),
        ];
        foreach ($invalid as $operation) {
            try {
                $operation();
                self::fail('Impossible counts/age must be rejected.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @return list<ReturnCurvePoint> */
    private static function points(int $quantity = 100, int $postings = 30, int $returnedAt20 = 20, int $returnedAt60 = 30, int $riskBound = 20): array
    {
        $points = [];
        for ($age = 0; $age <= 60; ++$age) {
            $returned = $age < 20 ? 0 : ($age < 40 ? $returnedAt20 : $returnedAt60);
            $risk = $quantity - $returned;
            $bound = 0 === $risk ? 0 : (0 === $returned ? $postings : min($postings, $riskBound, $risk));
            $points[] = new ReturnCurvePoint($age, $returned, $risk, $bound);
        }

        return $points;
    }

    private function assertFraction(int $numerator, int $denominator, ?ReturnProbability $probability): void
    {
        self::assertNotNull($probability);
        self::assertSame($numerator, $probability->numerator);
        self::assertSame($denominator, $probability->denominator);
    }
}
