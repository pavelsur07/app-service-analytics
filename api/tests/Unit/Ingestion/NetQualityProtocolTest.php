<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Application\Facade\ForwardCaptureRequest;
use App\Ingestion\Application\Facade\ForwardQualityProtocolRef;
use App\Ingestion\Application\NetForecast\NetForwardCaptureRequest;
use App\Tests\Support\Builder\NetQualityProtocolBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class NetQualityProtocolTest extends TestCase
{
    public function testAnEarlyCaptureCannotClaimARegisteredDailySlot(): void
    {
        $protocol = NetQualityProtocolBuilder::aProtocol()->build();
        $ref = new \App\Ingestion\Application\NetForecast\NetQualityArtifactRef(\App\Ingestion\Domain\NetForwardArtifactKind::QualityProtocol, $protocol->protocolId, str_repeat('a', 64));
        $context = new \App\Ingestion\Application\NetForecast\NetQualityCaptureContext($ref, $protocol, new \DateTimeImmutable('2027-01-01T00:00:00Z'));
        $request = new NetForwardCaptureRequest($protocol->slots[0]['requestId'], $protocol->planId, $protocol->from, $protocol->to, qualityProtocolId: $ref->requestId, qualityProtocolHash: $ref->sha256);
        $plan = new \App\Ingestion\Application\NetForecast\NetForwardPlanContext($protocol->planId, 1, 1, $protocol->baselineId, 1, 'retained_sales', 'RUB', $protocol->from, ['SKU'], [['sku' => 'SKU', 'points' => []]]);
        $this->expectException(\DomainException::class);
        $context->assertPlan($protocol->companyId, $protocol->accountId, $request, $plan, new \DateTimeImmutable('2027-01-04T08:59:59Z'));
    }

    public function testProtocolRejectsOverlappingOrderPopulationsBeforeCapture(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NetQualityProtocolBuilder::aProtocol()->with('holdoutFrom', '2026-12-15')->build();
    }

    public function testProtocolCannotHideMissingScheduledCapture(): void
    {
        $builder = NetQualityProtocolBuilder::aProtocol();
        $slots = $builder->data()['slots'];
        self::assertIsArray($slots);
        array_pop($slots);
        $this->expectException(\InvalidArgumentException::class);
        $builder->with('slots', $slots)->build();
    }

    public function testRegisteredCaptureIsBoundToScopeAndLegacyHashesStayCompatible(): void
    {
        $protocol = NetQualityProtocolBuilder::aProtocol()->build();
        $slot = $protocol->slots[0];
        $protocol->assertCapture($protocol->companyId, $protocol->accountId, $slot['requestId'], $protocol->planId, $protocol->from, $protocol->to);
        $legacy = new ForwardCaptureRequest($slot['requestId'], $protocol->planId, $protocol->from, $protocol->to);
        $coreLegacy = new NetForwardCaptureRequest($slot['requestId'], $protocol->planId, $protocol->from, $protocol->to);
        self::assertSame($legacy->hash(), $coreLegacy->hash());
        $ref = new ForwardQualityProtocolRef($protocol->protocolId, str_repeat('a', 64));
        $quality = new ForwardCaptureRequest($slot['requestId'], $protocol->planId, $protocol->from, $protocol->to, qualityProtocol: $ref);
        $coreQuality = new NetForwardCaptureRequest($slot['requestId'], $protocol->planId, $protocol->from, $protocol->to, qualityProtocolId: $ref->requestId, qualityProtocolHash: $ref->sha256);
        self::assertNotSame($legacy->hash(), $quality->hash());
        self::assertSame($quality->hash(), $coreQuality->hash());
        $this->expectException(\DomainException::class);
        $protocol->assertCapture($protocol->companyId, Uuid::v7()->toRfc4122(), $slot['requestId'], $protocol->planId, $protocol->from, $protocol->to);
    }
}
