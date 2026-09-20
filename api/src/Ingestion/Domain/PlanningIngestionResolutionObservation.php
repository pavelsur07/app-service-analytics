<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** First trusted observation of one allocation, not a copied outcome fact. */
#[ORM\Entity]
#[ORM\Table(name: 'planning_ingestion_resolution_observation')]
#[ORM\Index(name: 'idx_planning_resolution_known', columns: ['company_id', 'marketplace_account_id', 'first_known_outcome_at', 'allocation_key'])]
#[ORM\Index(name: 'idx_planning_resolution_regular', columns: ['company_id', 'marketplace_account_id', 'first_regularly_observed_at', 'allocation_key'])]
#[ORM\Index(name: 'idx_planning_resolution_undated', columns: ['company_id', 'marketplace_account_id', 'undated_since_at', 'source_row_id'], options: ['where' => '(undated_since_at IS NOT NULL)'])]
#[ORM\Index(name: 'idx_planning_resolution_latest_run', columns: ['company_id', 'marketplace_account_id', 'source_row_id', 'outcome', 'last_seen_run'])]
class PlanningIngestionResolutionObservation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $companyId;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $marketplaceAccountId;

    #[ORM\Id]
    #[ORM\Column(type: 'text')]
    private string $sourceRowId;

    #[ORM\Id]
    #[ORM\Column(length: 20)]
    private string $allocationKey;

    #[ORM\Id]
    #[ORM\Column(length: 2)]
    private string $outcome;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $firstObservedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $undatedSinceAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $firstKnownOutcomeAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $firstRegularlyObservedAt;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $dateLineage;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sourceEventAt;

    #[ORM\Column]
    private bool $backfill;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $rawDocumentId;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $lastSeenRun;

    public function __construct(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $sourceRowId,
        string $allocationKey,
        string $outcome,
        \DateTimeImmutable $firstObservedAt,
        ?\DateTimeImmutable $firstKnownOutcomeAt,
        ?\DateTimeImmutable $firstRegularlyObservedAt,
        ?\DateTimeImmutable $sourceEventAt,
        bool $backfill,
        ?Uuid $rawDocumentId,
        int $lastSeenRun = 0,
        ?string $dateLineage = null,
        ?\DateTimeImmutable $undatedSinceAt = null,
    ) {
        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->sourceRowId = $sourceRowId;
        $this->allocationKey = $allocationKey;
        $this->outcome = $outcome;
        $this->firstObservedAt = $firstObservedAt;
        $this->undatedSinceAt = $undatedSinceAt;
        $this->firstKnownOutcomeAt = $firstKnownOutcomeAt;
        $this->firstRegularlyObservedAt = $firstRegularlyObservedAt;
        $this->sourceEventAt = $sourceEventAt;
        $this->backfill = $backfill;
        $this->rawDocumentId = $rawDocumentId;
        $this->lastSeenRun = $lastSeenRun;
        $this->dateLineage = $dateLineage;
    }
}
