<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\ProductResearchOzon\Domain\PeriodRange;
use App\ProductResearchOzon\Domain\ResearchPeriod;
use App\ProductResearchOzon\Domain\ResearchProject;
use Doctrine\ORM\EntityManagerInterface;

final class ResearchPeriodBuilder
{
    private ResearchProject $project;
    private PeriodRange $range;
    private int $position = 0;

    private function __construct()
    {
        $this->project = ResearchProjectBuilder::aProject()->build();
        $this->range = new PeriodRange('2026-01', '2026-03', 'Весна');
    }

    public static function aPeriod(): self
    {
        return new self();
    }

    public function withProject(ResearchProject $project): self
    {
        $copy = clone $this;
        $copy->project = $project;

        return $copy;
    }

    public function withRange(PeriodRange $range): self
    {
        $copy = clone $this;
        $copy->range = $range;

        return $copy;
    }

    public function atPosition(int $position): self
    {
        $copy = clone $this;
        $copy->position = $position;

        return $copy;
    }

    public function build(): ResearchPeriod
    {
        return new ResearchPeriod($this->project, $this->range, $this->position);
    }

    public function persistWith(EntityManagerInterface $em): ResearchPeriod
    {
        $period = $this->build();
        $em->persist($this->project);
        $em->persist($period);
        $em->flush();

        return $period;
    }
}
