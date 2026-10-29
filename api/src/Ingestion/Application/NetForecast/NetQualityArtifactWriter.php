<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\NetForecastArtifactPublication;
use App\Ingestion\Domain\NetForwardArtifactKey;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;

final readonly class NetQualityArtifactWriter
{
    public function __construct(private NetForwardArtifactReader $reader, private PublishNetForecastCaptureAction $publisher, private NetForwardManifestCodec $codec, private ClockInterface $clock = new NativeClock())
    {
    }

    public function publish(string $companyId, string $accountId, NetQualityArtifactDocument $document, NetQualityBudget $budget): NetQualityArtifactRef
    {
        $this->reader->assertNoTransaction();
        $budget->guard();
        $parent = $document->parent;
        if (null !== $parent) {
            $this->reader->read($companyId, $accountId, $parent->kind, $parent->requestId, $parent->sha256, deadline: $budget->deadline, budget: $budget);
        }
        $spool = new NetForwardSpool($this->codec, $budget->deadline, $budget);
        foreach ($document->segments as $segment) {
            $budget->guard();
            $spool->append($segment['type'], $segment['row']);
        }

        return $this->publishRoot($companyId, $accountId, $document, $spool, $budget);
    }

    public function publishStaged(string $companyId, string $accountId, NetQualityArtifactDocument $document, NetForwardSpool $spool, NetQualityBudget $budget): NetQualityArtifactRef
    {
        if ([] !== $document->segments) {
            throw new \InvalidArgumentException('A staged document must use its existing sealed spool only.');
        }
        $this->reader->assertNoTransaction();
        $budget->guard();
        if (null !== $document->parent) {
            $this->reader->read($companyId, $accountId, $document->parent->kind, $document->parent->requestId, $document->parent->sha256, deadline: $budget->deadline, budget: $budget);
        }

        return $this->publishRoot($companyId, $accountId, $document, $spool, $budget);
    }

    private function publishRoot(string $companyId, string $accountId, NetQualityArtifactDocument $document, NetForwardSpool $spool, NetQualityBudget $budget): NetQualityArtifactRef
    {
        $spool->guard();
        $parent = $document->parent;
        $attempt = Uuid::v7()->toRfc4122();
        $root = $this->codec->encode(['companyId' => $companyId, 'marketplaceAccountId' => $accountId, 'kind' => $document->kind->value, 'requestId' => $document->requestId, 'requestHash' => $document->requestHash, 'attemptId' => $attempt, 'readAtUtc' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'), 'parent' => $parent?->toArray(), 'chunks' => $spool->descriptors(), 'metadata' => $document->metadata]);
        $publication = new NetForecastArtifactPublication(new NetForwardArtifactKey($companyId, $accountId, $document->kind, $document->requestId, $attempt, hash('sha256', $root)), $document->requestHash, \strlen($root));
        $verified = ($this->publisher)($companyId, $accountId, new PreparedNetForwardArtifact($publication, $root, $spool), $budget);

        return new NetQualityArtifactRef($verified->publication->root->kind, $verified->publication->root->requestId, $verified->publication->root->sha256);
    }
}
