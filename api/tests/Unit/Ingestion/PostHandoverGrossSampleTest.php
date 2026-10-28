<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Domain\ValueObject\GrossDeliveryQuantity;
use App\Ingestion\Domain\ValueObject\PostHandoverGrossSample;
use App\Ingestion\Domain\ValueObject\PreHandoverGrossSample;
use PHPUnit\Framework\TestCase;

final class PostHandoverGrossSampleTest extends TestCase
{
    public function testHundredGrossAndHundredT1HaveDifferentConditionalPostAndFullPreRates(): void
    {
        $source = new PreHandoverGrossSample(200, 60, 100, 100, 0, 0);
        $sample = new PostHandoverGrossSample($source, 30);
        $post = $sample->probability();
        self::assertNotNull($post);
        self::assertSame(100, $post->numerator);
        self::assertSame(100, $post->denominator);
        self::assertSame($source, $sample->source);
        self::assertSame(100, $sample->postHandoverQuantity);
        self::assertSame('100', GrossDeliveryQuantity::fromProbability(100, $post)->expectedGrossNumerator);

        $pre = $source->probability();
        self::assertNotNull($pre);
        self::assertSame(100, $pre->numerator);
        self::assertSame(200, $pre->denominator);
        self::assertSame('50', GrossDeliveryQuantity::fromProbability(100, $pre)->expectedGrossNumerator);
    }

    public function testInclusiveConditionalMinimumCannotBeReplacedByLargeFullSample(): void
    {
        foreach ([[99, 30, false], [100, 29, false], [100, 30, true]] as [$postQuantity, $postings, $available]) {
            $sample = new PostHandoverGrossSample(new PreHandoverGrossSample(1000, 100, 0, 1000 - $postQuantity, $postQuantity, 0), $postings);
            self::assertSame($available, null !== $sample->probability());
        }
    }

    public function testEmptyConditionalPopulationIsUnknownButSufficientGenuineZeroIsAvailable(): void
    {
        foreach ([[0, 0], [1000, 100]] as [$fullQuantity, $fullPostings]) {
            self::assertNull(new PostHandoverGrossSample(new PreHandoverGrossSample($fullQuantity, $fullPostings, 0, $fullQuantity, 0, 0), 0)->probability());
        }
        $probability = new PostHandoverGrossSample(new PreHandoverGrossSample(200, 60, 0, 100, 40, 60), 30)->probability();
        self::assertNotNull($probability);
        self::assertSame(0, $probability->numerator);
        self::assertSame(100, $probability->denominator);
    }

    public function testPartialT1AndPostOutcomesCanShareAllOriginalPostings(): void
    {
        $probability = new PostHandoverGrossSample(new PreHandoverGrossSample(101, 30, 60, 1, 30, 10), 30)->probability();
        self::assertNotNull($probability);
        self::assertSame(60, $probability->numerator);
        self::assertSame(100, $probability->denominator);
    }

    public function testLargePostDenominatorIsComputedBySubtractionWithoutOverflow(): void
    {
        $probability = new PostHandoverGrossSample(new PreHandoverGrossSample(\PHP_INT_MAX, 30, \PHP_INT_MAX - 3, 1, 1, 1), 30)->probability();
        self::assertNotNull($probability);
        self::assertSame(\PHP_INT_MAX - 3, $probability->numerator);
        self::assertSame(\PHP_INT_MAX - 1, $probability->denominator);
    }

    public function testRejectsImpossibleDedicatedPostingsWithoutAssumingDisjointSets(): void
    {
        foreach ([[200, 60, 100, -1], [200, 60, 100, 61], [100, 60, 99, 2], [200, 60, 100, 0], [200, 60, 200, 1]] as [$full, $fullPostings, $t1, $postings]) {
            try {
                new PostHandoverGrossSample(new PreHandoverGrossSample($full, $fullPostings, $full - $t1, $t1, 0, 0), $postings);
                self::fail('Impossible conditional count accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testFullRawConservationMustPassBeforeAnyConditionalRate(): void
    {
        foreach ([[-1, 0, 0, 0, 0, 0], [200, 60, -1, 201, 0, 0],
            [200, 60, 100, 99, 0, 0], [200, 60, 100, 101, 0, 0],
            [200, 0, 100, 100, 0, 0], [200, 201, 100, 100, 0, 0],
            [\PHP_INT_MAX, 30, \PHP_INT_MAX, \PHP_INT_MAX, 0, 0],
            [\PHP_INT_MAX, 30, \PHP_INT_MAX - 1, 0, 1, \PHP_INT_MAX]] as $counts) {
            try {
                new PostHandoverGrossSample(new PreHandoverGrossSample(...$counts), 30);
                self::fail('Invalid raw source accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testFullPostingsOutsideConditionalPopulationNeedAtLeastOneT1UnitEach(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PostHandoverGrossSample(new PreHandoverGrossSample(100, 60, 100, 0, 0, 0), 30);
    }
}
