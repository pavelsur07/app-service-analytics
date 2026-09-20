<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Successful routine polling by observation day for buyout/velocity windows. */
#[ORM\Entity]
#[ORM\Table(name: 'planning_ingestion_day_coverage')]
class PlanningIngestionDayCoverage
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $companyId;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $marketplaceAccountId;

    #[ORM\Id]
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $observationDate;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $postingsCheckedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $returnsCheckedAt;

    public function __construct(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        \DateTimeImmutable $observationDate,
        ?\DateTimeImmutable $postingsCheckedAt,
        ?\DateTimeImmutable $returnsCheckedAt,
    ) {
        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->observationDate = $observationDate;
        $this->postingsCheckedAt = $postingsCheckedAt;
        $this->returnsCheckedAt = $returnsCheckedAt;
    }
}
