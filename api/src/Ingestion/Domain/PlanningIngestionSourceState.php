<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Metadata of a fully parsed source window; DBAL writes, ORM maps schema. */
#[ORM\Entity]
#[ORM\Table(name: 'planning_ingestion_source_state')]
#[ORM\Index(name: 'idx_planning_source_last_complete', columns: ['company_id', 'marketplace_account_id', 'source_kind', 'last_complete_at'])]
class PlanningIngestionSourceState
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $companyId;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $marketplaceAccountId;

    #[ORM\Id]
    #[ORM\Column(length: 16)]
    private string $sourceKind;

    #[ORM\Id]
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $fromDate;

    #[ORM\Id]
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $toDate;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $contentHash;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $lastCompleteAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastRegularCompleteAt;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastRegularWindowTo;

    #[ORM\Column(length: 8)]
    private string $lastOrigin;

    public function __construct(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $sourceKind,
        \DateTimeImmutable $fromDate,
        \DateTimeImmutable $toDate,
        string $contentHash,
        \DateTimeImmutable $lastCompleteAt,
        ?\DateTimeImmutable $lastRegularCompleteAt,
        ?\DateTimeImmutable $lastRegularWindowTo,
        string $lastOrigin,
    ) {
        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->sourceKind = $sourceKind;
        $this->fromDate = $fromDate;
        $this->toDate = $toDate;
        $this->contentHash = $contentHash;
        $this->lastCompleteAt = $lastCompleteAt;
        $this->lastRegularCompleteAt = $lastRegularCompleteAt;
        $this->lastRegularWindowTo = $lastRegularWindowTo;
        $this->lastOrigin = $lastOrigin;
    }
}
