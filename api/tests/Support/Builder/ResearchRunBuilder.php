<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\ProductResearchOzon\Domain\MonthSlice;
use App\ProductResearchOzon\Domain\ResearchProject;
use App\ProductResearchOzon\Domain\ResearchRun;
use App\ProductResearchOzon\Domain\ResearchRunRepository;
use Symfony\Component\Uid\Uuid;

final class ResearchRunBuilder
{
    private ResearchProject $project;
    private Uuid $source;
    private Uuid $request;
    /** @var list<MonthSlice> */
    private array $months;

    private function __construct()
    {
        $this->project = ResearchProjectBuilder::aProject()->build();
        $this->source = Uuid::v7();
        $this->request = Uuid::v7();
        $this->months = [new MonthSlice('2026-01', '2026-01-01', '2026-01-31', 31, false)];
    }

    public static function aRun(): self
    {
        return new self();
    }

    public function withProject(ResearchProject $project): self
    {
        $copy = clone $this;
        $copy->project = $project;

        return $copy;
    }

    public function withSource(Uuid $source): self
    {
        $copy = clone $this;
        $copy->source = $source;

        return $copy;
    }

    /** @param list<MonthSlice> $months */
    public function withMonths(array $months): self
    {
        $copy = clone $this;
        $copy->months = $months;

        return $copy;
    }

    public function build(): ResearchRun
    {
        return new ResearchRun($this->project, $this->source, $this->request, hash('sha256', 'builder'), $this->months, Uuid::v7(), new \DateTimeImmutable('2026-04-15T09:00:00Z'));
    }

    public function persistWith(ResearchRunRepository $repository): ResearchRun
    {
        $run = $this->build();
        $repository->add($run, $this->months);

        return $run;
    }
}
