<?php

declare(strict_types=1);

namespace App\Tests\Support\Ingestion;

use App\Ingestion\Domain\NetForwardArtifactKey;
use App\Ingestion\Domain\NetForwardArtifactMissing;
use App\Ingestion\Domain\NetForwardArtifactStorage;

final class MemoryNetForwardArtifactStorage implements NetForwardArtifactStorage
{
    /** @var array<string, string> */
    public array $objects = [];
    public bool $failReportWrites = false;

    public function putContent(string $companyId, string $accountId, NetForwardArtifactKey $key, string $body): void
    {
        $key->assertScope($companyId, $accountId);
        if ($this->failReportWrites && \App\Ingestion\Domain\NetForwardArtifactKind::QualityReport === $key->kind) {
            throw new \RuntimeException('Injected report write failure.');
        }
        if (hash('sha256', $body) !== $key->sha256) {
            throw new \UnexpectedValueException('Test storage hash mismatch.');
        }
        $this->objects[$key->toString()] = $body;
    }

    public function get(string $companyId, string $accountId, NetForwardArtifactKey $key, int $maxBytes): string
    {
        $key->assertScope($companyId, $accountId);
        $body = $this->objects[$key->toString()] ?? throw new NetForwardArtifactMissing();
        if (\strlen($body) > $maxBytes) {
            throw new \OverflowException('Test storage byte limit exceeded.');
        }

        return $body;
    }
}
