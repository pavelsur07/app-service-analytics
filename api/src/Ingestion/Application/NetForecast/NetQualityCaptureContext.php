<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

final readonly class NetQualityCaptureContext
{
    public function __construct(public NetQualityArtifactRef $protocolRef, public NetQualityProtocol $protocol, public \DateTimeImmutable $frozenAt)
    {
    }

    public function assertPlan(string $companyId, string $accountId, NetForwardCaptureRequest $request, NetForwardPlanContext $plan, \DateTimeImmutable $readAt): void
    {
        $this->protocol->assertCapture($companyId, $accountId, $request->requestId, $request->planId, $request->from, $request->to);
        $skus = $plan->marketplaceSkus;
        sort($skus, \SORT_STRING);
        foreach ($this->protocol->slots as $slot) {
            if ($slot['requestId'] === $request->requestId && $readAt < NetQualitySchema::utc($slot['scheduledAtUtc'])) {
                throw new \DomainException('Capture precedes its registered daily slot.');
            }
        }
        if ($request->qualityProtocolId !== $this->protocolRef->requestId || $request->qualityProtocolHash !== $this->protocolRef->sha256 || $this->frozenAt >= $readAt || $readAt < NetQualitySchema::utc(NetQualitySchema::string($this->protocol->data, 'captureFromUtc')) || $readAt >= NetQualitySchema::utc(NetQualitySchema::string($this->protocol->data, 'captureToUtc')) || $plan->baselineId !== $this->protocol->baselineId || $skus !== $this->protocol->skus || !\in_array($plan->currency, $this->protocol->currencies, true)) {
            throw new \DomainException('Visible plan or read frame conflicts with the registered protocol.');
        }
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return ['protocol' => $this->protocolRef->toArray(), 'frozenAtUtc' => $this->frozenAt->format('Y-m-d\TH:i:s.u\Z')];
    }
}
