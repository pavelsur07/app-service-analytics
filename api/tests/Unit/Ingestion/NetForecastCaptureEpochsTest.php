<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Infrastructure\Config\NetForecastCaptureEpochs;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class NetForecastCaptureEpochsTest extends TestCase
{
    public function testMissingOrClosedCaptureNeverBecomesUsableByItsTimestamp(): void
    {
        $now = new \DateTimeImmutable('2027-01-01T00:00:00Z');
        self::assertNull((new NetForecastCaptureEpochs())->at($now));
        $closed = $this->segment('2026-10-28T05:54:45Z', '2026-11-01T00:00:00Z');
        self::assertNull((new NetForecastCaptureEpochs(json_encode([$closed], \JSON_THROW_ON_ERROR)))->at($now));
    }

    public function testReadySegmentRetainsTheTrustedCutoverAndUsesExclusiveEnd(): void
    {
        $segment = $this->segment('2026-10-28T05:54:45Z', '2027-01-01T00:00:00Z');
        $config = new NetForecastCaptureEpochs(json_encode([$segment], \JSON_THROW_ON_ERROR));
        $epoch = $config->at(new \DateTimeImmutable('2026-12-31T23:59:59Z'));
        self::assertNotNull($epoch);
        self::assertSame($segment['epochId'], $epoch->id);
        self::assertSame('639-6ceaa3e', $epoch->cutoverRelease);
        self::assertSame('2026-10-28T05:54:45Z', $epoch->start->format('Y-m-d\TH:i:s\Z'));
        self::assertNull($config->at(new \DateTimeImmutable('2027-01-01T00:00:00Z')));
    }

    public function testMalformedOrUntrustedConfigurationCannotClaimContinuousCoverage(): void
    {
        $valid = $this->segment('2026-10-28T05:54:45Z', null);
        foreach ([
            'broken', '{}',
            json_encode([array_replace($valid, ['captureVersion' => 'invented'])], \JSON_THROW_ON_ERROR),
            json_encode([array_replace($valid, ['epochId' => 'not-an-id'])], \JSON_THROW_ON_ERROR),
            json_encode([array_replace($valid, ['startUtc' => '2026-02-30T00:00:00Z'])], \JSON_THROW_ON_ERROR),
            json_encode([array_replace($valid, ['startUtc' => '2026-10-28T08:54:45+03:00'])], \JSON_THROW_ON_ERROR),
            json_encode([array_replace($valid, ['endUtc' => '2026-10-27T00:00:00Z'])], \JSON_THROW_ON_ERROR),
            json_encode([array_replace($valid, ['guessed' => true])], \JSON_THROW_ON_ERROR),
            json_encode([$valid, $this->segment('2026-11-01T00:00:00Z', null)], \JSON_THROW_ON_ERROR),
            json_encode(array_fill(0, 17, $valid), \JSON_THROW_ON_ERROR),
        ] as $json) {
            try {
                (new NetForecastCaptureEpochs($json))->at(new \DateTimeImmutable('2027-01-01T00:00:00Z'));
                self::fail('Invalid capture configuration must not establish an epoch.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @return array{epochId:string,captureVersion:string,startUtc:string,endUtc:?string,cutoverRelease:string} */
    private function segment(string $start, ?string $end): array
    {
        return ['epochId' => Uuid::v7()->toRfc4122(), 'captureVersion' => 'accepted_state_v1', 'startUtc' => $start, 'endUtc' => $end, 'cutoverRelease' => '639-6ceaa3e'];
    }
}
