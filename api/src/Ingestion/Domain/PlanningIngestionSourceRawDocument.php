<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Native UUID link from one complete source window to an immutable raw page. */
#[ORM\Entity]
#[ORM\Table(name: 'planning_ingestion_source_raw_document')]
class PlanningIngestionSourceRawDocument
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

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $rawDocumentId;

    public function __construct(Uuid $companyId, Uuid $marketplaceAccountId, string $sourceKind, \DateTimeImmutable $fromDate, \DateTimeImmutable $toDate, Uuid $rawDocumentId)
    {
        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->sourceKind = $sourceKind;
        $this->fromDate = $fromDate;
        $this->toDate = $toDate;
        $this->rawDocumentId = $rawDocumentId;
    }
}
