<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Monotone account generation changes only with source content or coverage. */
#[ORM\Entity]
#[ORM\Table(name: 'planning_ingestion_account_state')]
class PlanningIngestionAccountState
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $companyId;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $marketplaceAccountId;

    #[ORM\Column(type: 'bigint')]
    private int $generation;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $observationRun;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $baselineCompletedAt;

    public function __construct(Uuid $companyId, Uuid $marketplaceAccountId, int $generation, \DateTimeImmutable $updatedAt, ?\DateTimeImmutable $baselineCompletedAt, int $observationRun = 0)
    {
        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->generation = $generation;
        $this->observationRun = $observationRun;
        $this->updatedAt = $updatedAt;
        $this->baselineCompletedAt = $baselineCompletedAt;
    }
}
