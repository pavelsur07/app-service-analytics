<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Domain\ValueObject\NetForecastCaptureEpoch;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingContext;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingCursor;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingPopulation;
use App\Ingestion\Infrastructure\Query\NetForecast\NetTrainingRequest;
use App\Ingestion\Infrastructure\Query\NetForecast\TrainingGroupDiagnosticRow;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class NetTrainingCursorTest extends TestCase
{
    public function testCursorCannotCrossTheReadTimePopulationEpochOrSkuBoundary(): void
    {
        $at = new \DateTimeImmutable('2026-12-01', new \DateTimeZone('UTC'));
        $epoch = new NetForecastCaptureEpoch(Uuid::v7()->toRfc4122(), 'accepted_state_v1', $at->modify('-100 days'), null, 'synthetic');
        $context = new NetTrainingContext(Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), $at, $epoch, '1:2:');
        $request = new NetTrainingRequest(['SKU']);
        $cursor = NetTrainingCursor::fromString(NetTrainingCursor::afterRow($context, $request, NetTrainingPopulation::Gross, new TrainingGroupDiagnosticRow('2026-11-01', 'O', 'O', 'SKU', false, false, 'unknown_outcome', true))->toString());
        $cursor->assertContext($context, $request, NetTrainingPopulation::Gross);
        self::assertSame('O', $cursor->orderKey);
        $otherEpoch = new NetForecastCaptureEpoch(Uuid::v7()->toRfc4122(), 'accepted_state_v1', $epoch->start, null, 'synthetic');
        foreach ([
            [$context, new NetTrainingRequest(['OTHER']), NetTrainingPopulation::Gross],
            [$context, $request, NetTrainingPopulation::Returns],
            [new NetTrainingContext($context->companyId, $context->accountId, $at->modify('+1 second'), $epoch, '1:2:'), $request, NetTrainingPopulation::Gross],
            [new NetTrainingContext($context->companyId, $context->accountId, $at, $otherEpoch, '1:2:'), $request, NetTrainingPopulation::Gross],
        ] as [$next, $skus, $population]) {
            try {
                $cursor->assertContext($next, $skus, $population);
                self::fail('A diagnostic cursor must remain within its original read boundary.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testMalformedOrUnboundedCursorInputIsRejected(): void
    {
        foreach (['', '%bad', str_repeat('A', 8193), rtrim(base64_encode('{"object":true}'), '='), rtrim(base64_encode('[null,null,null,null,null,null,null]'), '=')] as $value) {
            try {
                NetTrainingCursor::fromString($value);
                self::fail('Malformed cursor must be rejected before querying.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
