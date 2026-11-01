<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain;

use App\ProductResearchOzon\Domain\ValueObject\RunMonthState;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'product_research_ozon_run_month')]
#[ORM\UniqueConstraint(name: 'uq_research_run_month', columns: ['company_id', 'run_id', 'month'])]
class ResearchRunMonth
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;
    #[ORM\ManyToOne(targetEntity: ResearchRun::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'company_id', nullable: false)]
    #[ORM\JoinColumn(name: 'run_id', referencedColumnName: 'id', nullable: false)]
    private ResearchRun $run;
    #[ORM\Column(length: 7)]
    private string $month;
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateFrom;
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateTo;
    #[ORM\Column]
    private int $days;
    #[ORM\Column]
    private bool $partial;
    #[ORM\Column(length: 16)]
    private string $status = 'queued';
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $snapshotId = null;
    #[ORM\Column(nullable: true)]
    private ?int $rowCount = null;
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $errorCode = null;

    public function __construct(ResearchRun $run, MonthSlice $month)
    {
        new RunMonthState($month, 'queued', null, null, null);
        $this->id = Uuid::v7();
        $this->run = $run;
        $this->month = $month->month;
        $this->dateFrom = new \DateTimeImmutable($month->dateFrom, new \DateTimeZone('UTC'));
        $this->dateTo = new \DateTimeImmutable($month->dateTo, new \DateTimeZone('UTC'));
        $this->days = $month->days;
        $this->partial = $month->partial;
    }
}
