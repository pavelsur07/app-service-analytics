<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\ProductResearchOzon\Domain\CategoryReference;
use App\ProductResearchOzon\Domain\PeriodRange;
use App\ProductResearchOzon\Domain\ResearchProject;
use App\ProductResearchOzon\Domain\ResearchProjectRepository;
use Symfony\Component\Uid\Uuid;

final class ResearchProjectBuilder
{
    private Uuid $company;
    private Uuid $actor;
    private string $name = 'Исследование';
    /** @var list<PeriodRange> */
    private array $periods;

    private function __construct()
    {
        $this->company = Uuid::v7();
        $this->actor = Uuid::v7();
        $this->periods = [new PeriodRange('2026-01', '2026-03', 'Весна')];
    }

    public static function aProject(): self
    {
        return new self();
    }

    public function withCompanyId(Uuid $company): self
    {
        $copy = clone $this;
        $copy->company = $company;

        return $copy;
    }

    public function withName(string $name): self
    {
        $copy = clone $this;
        $copy->name = $name;

        return $copy;
    }

    public function build(): ResearchProject
    {
        return new ResearchProject($this->company, new CategoryReference('Одежда/Хиджабы', 1), $this->name, 'Комментарий', false, $this->actor, new \DateTimeImmutable('2026-04-15T09:00:00Z'), \count($this->periods));
    }

    public function persistWith(ResearchProjectRepository $repository): ResearchProject
    {
        $project = $this->build();
        $repository->transactional(fn () => $repository->add($project, $this->periods));

        return $project;
    }
}
