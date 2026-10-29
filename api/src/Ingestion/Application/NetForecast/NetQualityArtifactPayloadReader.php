<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

/** Strict single-document quality payloads; datasets use the streaming segment reader. */
final readonly class NetQualityArtifactPayloadReader
{
    public function __construct(private NetForwardArtifactReader $reader)
    {
    }

    /** @return array{VerifiedNetForwardArtifact, array<string, mixed>} */
    public function read(string $companyId, string $accountId, NetQualityArtifactRef $ref, string $type, NetQualityBudget $budget): array
    {
        $artifact = $this->reader->read($companyId, $accountId, $ref->kind, $ref->requestId, $ref->sha256, deadline: $budget->deadline, budget: $budget);
        $value = null;
        foreach ($this->reader->segments($companyId, $accountId, $artifact, $budget->deadline, $budget) as $segment) {
            foreach ($segment['rows'] as $row) {
                if ($segment['type'] !== $type || null !== $value || !\is_array($row['value'] ?? null)) {
                    throw new \UnexpectedValueException('Unexpected or duplicate quality document payload.');
                }
                NetQualitySchema::keys($row, ['value']);
                $value = NetQualitySchema::rows([$row['value']], 1)[0];
            }
        }
        if (null === $value) {
            throw new \UnexpectedValueException('Quality document payload missing.');
        }

        return [$artifact, $value];
    }
}
