<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Disposable technical projection; writes use DBAL, never human project mutations. */
#[ORM\Entity]
#[ORM\Table(name: 'product_research_ozon_catalog')]
class CatalogProjection
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $sourceConnectionId;
    #[ORM\Column(type: 'bigint')]
    private readonly int $revision;
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true, columnDefinition: 'TIMESTAMP(6) WITH TIME ZONE')]
    private readonly ?\DateTimeImmutable $generatedAt;
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $leaseId = null;
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true, columnDefinition: 'TIMESTAMP(6) WITH TIME ZONE')]
    private ?\DateTimeImmutable $leaseUntil = null;
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true, columnDefinition: 'TIMESTAMP(6) WITH TIME ZONE')]
    private ?\DateTimeImmutable $nextAttemptAt = null;
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $errorCode = null;

    public function __construct(Uuid $company, Uuid $source, int $revision = 0, ?\DateTimeImmutable $generatedAt = null)
    {
        if ($revision < 0 || (0 === $revision) !== (null === $generatedAt)) {
            throw new \InvalidArgumentException('invalid_catalog_projection');
        }
        $this->companyId = $company;
        $this->sourceConnectionId = $source;
        $this->revision = $revision;
        $this->generatedAt = $generatedAt;
    }

    public function companyId(): Uuid
    {
        return $this->companyId;
    }

    public function sourceId(): Uuid
    {
        return $this->sourceConnectionId;
    }
}
