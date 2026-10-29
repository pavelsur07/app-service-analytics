<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Immutable publication fact; only DBAL inserts, no ORM writes. */
#[ORM\Entity]
#[ORM\Table(name: 'net_forecast_artifact')]
class NetForecastArtifact
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $marketplaceAccountId;

    #[ORM\Id]
    #[ORM\Column(length: 5)]
    private readonly string $kind;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $requestId;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private readonly string $requestHash;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $attemptId;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private readonly string $artifactHash;

    #[ORM\Column]
    private readonly int $manifestByteSize;

    #[ORM\Column(type: 'datetime_immutable')]
    private readonly \DateTimeImmutable $publishedAt;

    public function __construct(NetForecastArtifactPublication $publication, \DateTimeImmutable $publishedAt)
    {
        $key = $publication->root;
        $this->companyId = Uuid::fromString($key->companyId);
        $this->marketplaceAccountId = Uuid::fromString($key->accountId);
        $this->kind = $key->kind->value;
        $this->requestId = Uuid::fromString($key->requestId);
        $this->requestHash = $publication->requestHash;
        $this->attemptId = Uuid::fromString($key->attemptId);
        $this->artifactHash = $key->sha256;
        $this->manifestByteSize = $publication->manifestByteSize;
        $this->publishedAt = $publishedAt->setTimezone(new \DateTimeZone('UTC'));
    }
}
