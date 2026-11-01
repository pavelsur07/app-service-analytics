<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'product_research_ozon_period')]
#[ORM\UniqueConstraint(name: 'uq_research_period_range', columns: ['company_id', 'project_id', 'from_month', 'to_month', 'name'])]
#[ORM\Index(name: 'idx_research_period_order', columns: ['company_id', 'project_id', 'position'])]
class ResearchPeriod
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $id;
    #[ORM\ManyToOne(targetEntity: ResearchProject::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'company_id', nullable: false)]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: false)]
    private readonly ResearchProject $project;
    #[ORM\Column(type: 'date_immutable')]
    private readonly \DateTimeImmutable $fromMonth;
    #[ORM\Column(type: 'date_immutable')]
    private readonly \DateTimeImmutable $toMonth;
    #[ORM\Column(length: 100)]
    private readonly string $name;
    #[ORM\Column]
    private readonly int $position;

    public function __construct(ResearchProject $project, PeriodRange $range, int $position)
    {
        if ($position < 0 || $position >= 24) {
            throw new \InvalidArgumentException('period_limit');
        }
        $this->id = Uuid::v7();
        $this->project = $project;
        $this->fromMonth = new \DateTimeImmutable($range->fromMonth.'-01', new \DateTimeZone('UTC'));
        $this->toMonth = new \DateTimeImmutable($range->toMonth.'-01', new \DateTimeZone('UTC'));
        $this->name = $range->name;
        $this->position = $position;
    }

    public function range(): PeriodRange
    {
        return new PeriodRange($this->fromMonth->format('Y-m'), $this->toMonth->format('Y-m'), $this->name);
    }
}
