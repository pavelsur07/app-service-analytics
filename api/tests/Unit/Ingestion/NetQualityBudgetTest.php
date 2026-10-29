<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Application\NetForecast\NetQualityArtifactRef;
use App\Ingestion\Application\NetForecast\NetQualityBudget;
use App\Ingestion\Domain\NetForwardArtifactKind;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class NetQualityBudgetTest extends TestCase
{
    public function testTwoOutputSpoolsCannotEachSpendTheWholeSharedAllowance(): void
    {
        $budget = new NetQualityBudget(maxSpoolBytes: 1000);
        $codec = new \App\Ingestion\Application\NetForecast\NetForwardManifestCodec();
        $first = new \App\Ingestion\Application\NetForecast\NetForwardSpool($codec, qualityBudget: $budget);
        $first->append('test', ['value' => str_repeat('a', 600)]);
        $first->descriptors();
        $second = new \App\Ingestion\Application\NetForecast\NetForwardSpool($codec, qualityBudget: $budget);
        $second->append('test', ['value' => str_repeat('b', 600)]);
        $this->expectException(\OverflowException::class);
        $second->descriptors();
    }

    public function testRepeatedReadsConsumeOneBudgetAndFailBeforeExceedingIt(): void
    {
        $budget = new NetQualityBudget(maxReadBytes: 100);
        $budget->readBytes(60);
        $budget->readBytes(40);
        self::assertSame(100, $budget->summary()['readBytes']);
        $this->expectException(\OverflowException::class);
        $budget->readBytes(1);
    }

    public function testCandidatePopulationCannotBeSilentlyTruncated(): void
    {
        $budget = new NetQualityBudget(maxCandidates: 2);
        $budget->candidate();
        $budget->candidate();
        $this->expectException(\OverflowException::class);
        $budget->candidate();
    }

    public function testExpiredBudgetNeverStartsAnotherRead(): void
    {
        $budget = new NetQualityBudget(deadline: hrtime(true) - 1);
        $this->expectException(\OverflowException::class);
        $budget->readBytes(1);
    }

    public function testQualityRefRejectsExternalKeyAndRequiresExpectedHash(): void
    {
        $ref = new NetQualityArtifactRef(NetForwardArtifactKind::QualityProtocol, Uuid::v7()->toRfc4122(), str_repeat('a', 64));
        self::assertSame('quality-protocol', $ref->toArray()['kind']);
        $this->expectException(\InvalidArgumentException::class);
        new NetQualityArtifactRef(NetForwardArtifactKind::QualityProtocol, 's3://foreign/key', str_repeat('a', 64));
    }
}
