<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Last complete observation of a source row, including runs without known outcomes. */
#[ORM\Entity]
#[ORM\Table(name: 'planning_ingestion_source_row_run')]
class PlanningIngestionSourceRowRun
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

    #[ORM\Column(type: 'bigint')]
    private int $lastSeenRun;

    public function __construct(Uuid $companyId, Uuid $marketplaceAccountId, string $sourceRowId, int $lastSeenRun)
    {
        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->sourceRowId = $sourceRowId;
        $this->lastSeenRun = $lastSeenRun;
    }
}
