<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Domain\NetForecastArtifact;
use App\Ingestion\Domain\NetForecastArtifactPublication;
use App\Ingestion\Domain\NetForwardArtifactKey;
use App\Ingestion\Domain\NetForwardArtifactKind;
use Symfony\Component\Uid\Uuid;

/** Builds the mapped Entity; fact rows are still persisted only through the DBAL writer. */
final class NetForecastArtifactBuilder
{
    private NetForecastArtifactPublication $publication;
    private \DateTimeImmutable $publishedAt;

    private function __construct()
    {
        $this->publication = new NetForecastArtifactPublication(
            new NetForwardArtifactKey(Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), NetForwardArtifactKind::Input, Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), hash('sha256', 'artifact')),
            hash('sha256', 'request'),
            1,
        );
        $this->publishedAt = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
    }

    public static function aNetForecastArtifact(): self
    {
        return new self();
    }

    public function withPublication(NetForecastArtifactPublication $publication): self
    {
        $clone = clone $this;
        $clone->publication = $publication;

        return $clone;
    }

    public function withPublicationIdentity(string $companyId, string $accountId, NetForwardArtifactKind $kind, string $requestId, string $attemptId, string $artifactHash, string $requestHash, int $manifestByteSize): self
    {
        $clone = clone $this;
        $clone->publication = new NetForecastArtifactPublication(new NetForwardArtifactKey($companyId, $accountId, $kind, $requestId, $attemptId, $artifactHash), $requestHash, $manifestByteSize);

        return $clone;
    }

    public function withPublishedAt(\DateTimeImmutable $publishedAt): self
    {
        $clone = clone $this;
        $clone->publishedAt = $publishedAt;

        return $clone;
    }

    public function build(): NetForecastArtifact
    {
        return new NetForecastArtifact($this->publication, $this->publishedAt);
    }

    public function publication(): NetForecastArtifactPublication
    {
        return $this->publication;
    }
}
