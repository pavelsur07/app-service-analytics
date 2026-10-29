<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\NetForwardArtifactKind;

final readonly class NetQualityProtocolReader
{
    public function __construct(private NetForwardArtifactReader $reader)
    {
    }

    public function read(string $companyId, string $accountId, NetQualityArtifactRef $ref, NetQualityBudget $budget): NetQualityProtocol
    {
        return $this->load($companyId, $accountId, $ref, $budget)[0];
    }

    public function context(string $companyId, string $accountId, NetQualityArtifactRef $ref, NetQualityBudget $budget): NetQualityCaptureContext
    {
        [$protocol, $frozenAt] = $this->load($companyId, $accountId, $ref, $budget);

        return new NetQualityCaptureContext($ref, $protocol, $frozenAt);
    }

    public function forCapture(string $companyId, string $accountId, NetForwardCaptureRequest $request, NetQualityArtifactRef $ref, NetQualityBudget $budget): NetQualityCaptureContext
    {
        [$protocol, $frozenAt] = $this->load($companyId, $accountId, $ref, $budget);
        $protocol->assertCapture($companyId, $accountId, $request->requestId, $request->planId, $request->from, $request->to);
        if ($request->qualityProtocolId !== $ref->requestId || $request->qualityProtocolHash !== $ref->sha256 || 'day' !== $request->groupBy) {
            throw new \DomainException('Capture request must carry the verified quality protocol.');
        }

        return new NetQualityCaptureContext($ref, $protocol, $frozenAt);
    }

    /** @return array{NetQualityProtocol, \DateTimeImmutable} */
    private function load(string $companyId, string $accountId, NetQualityArtifactRef $ref, NetQualityBudget $budget): array
    {
        if (NetForwardArtifactKind::QualityProtocol !== $ref->kind) {
            throw new \DomainException('Expected a quality protocol reference.');
        }
        $artifact = $this->reader->read($companyId, $accountId, $ref->kind, $ref->requestId, $ref->sha256, deadline: $budget->deadline, budget: $budget);
        $protocol = null;
        foreach ($this->reader->segments($companyId, $accountId, $artifact, $budget->deadline, $budget) as $segment) {
            foreach ($segment['rows'] as $row) {
                if ('quality_protocol' !== $segment['type'] || null !== $protocol || !\is_array($row['value'] ?? null)) {
                    throw new \UnexpectedValueException('Incomplete or duplicate quality protocol.');
                }
                $value = NetQualitySchema::rows([$row['value']], 1)[0];
                $protocol = new NetQualityProtocol($value);
            }
        }
        $metadata = $artifact->manifest['metadata'];
        if (null === $protocol || !\is_array($metadata) || ($metadata['codeCommit'] ?? null) !== ($protocol->data['codeCommit'] ?? null) || $protocol->protocolId !== $ref->requestId || $protocol->companyId !== $companyId || $protocol->accountId !== $accountId || $artifact->readAt >= NetQualitySchema::utc(NetQualitySchema::string($protocol->data, 'captureFromUtc'))) {
            throw new \UnexpectedValueException('Protocol scope or pre-registration mismatch.');
        }

        return [$protocol, $artifact->readAt];
    }
}
