<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Application;

use App\Identity\Application\Facade\IdentityFacade;
use App\ProductResearchOzon\Domain\ResearchProject;
use App\ProductResearchOzon\Domain\ResearchProjectRepository;
use Symfony\Component\Uid\Uuid;

final readonly class CreateResearchProjectAction
{
    public function __construct(private ResearchProjectRepository $projects, private ResearchPreparation $prepare, private IdentityFacade $identity)
    {
    }

    public function __invoke(string $companyId, CreateProjectInput $input, string $actor): ProjectMutationResult
    {
        $at = $this->prepare->now();
        $preview = $this->prepare->preview($input->selection, $input->includeCurrentMonth, $at, $input->previewCalendarDate);
        $category = $this->prepare->category($companyId, $input->categoryPath);
        $name = null === $input->name || '' === trim($input->name) ? $preview->suggestedName($category->path) : $input->name;
        $project = new ResearchProject(Uuid::fromString($companyId), $category, $name, $input->comment, $input->includeCurrentMonth, Uuid::fromString($actor), $at, \count($preview->periods));

        return $this->projects->transactional(function () use ($project, $preview, $companyId, $actor): ProjectMutationResult {
            $this->projects->add($project, $preview->periods);
            $this->identity->recordAuditEntry($companyId, $actor, 'product_research_ozon.project_created', $project->id()->toRfc4122(), null, json_encode(['periodCount' => \count($preview->periods), 'monthCount' => \count($preview->months)], \JSON_THROW_ON_ERROR));

            return new ProjectMutationResult($project, $preview);
        });
    }
}
