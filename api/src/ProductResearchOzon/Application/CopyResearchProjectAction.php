<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Application;

use App\Identity\Application\Facade\IdentityFacade;
use App\ProductResearchOzon\Domain\CalendarSelection;
use App\ProductResearchOzon\Domain\ResearchProject;
use App\ProductResearchOzon\Domain\ResearchProjectRepository;
use Symfony\Component\Uid\Uuid;

final readonly class CopyResearchProjectAction
{
    public function __construct(private ResearchProjectRepository $projects, private ResearchPreparation $prepare, private IdentityFacade $identity)
    {
    }

    public function __invoke(string $companyId, string $projectId, CopyProjectInput $input, string $actor): ProjectMutationResult
    {
        return $this->projects->transactional(function () use ($companyId, $projectId, $input, $actor): ProjectMutationResult {
            $source = $this->projects->locked($companyId, $projectId, $input->expectedVersion, true);
            $selection = $input->selection ?? new CalendarSelection($this->projects->periods($companyId, $projectId));
            $flag = $input->includeCurrentMonth ?? $source->includeCurrentMonth();
            $at = $this->prepare->now();
            $preview = $this->prepare->preview($selection, $flag, $at, $input->previewCalendarDate);
            $category = $this->prepare->category($companyId, $input->categoryPath ?? $source->categoryPath());
            $name = $input->name ?? $source->name();
            if ('' === trim($name)) {
                $name = $preview->suggestedName($category->path);
            }
            $copy = new ResearchProject(Uuid::fromString($companyId), $category, $name, $input->comment ?? $source->comment(), $flag, Uuid::fromString($actor), $at, \count($preview->periods));
            $copy->freezeRequirements($preview->months);
            $this->projects->add($copy, $preview->periods);
            $this->identity->recordAuditEntry($companyId, $actor, 'product_research_ozon.project_copied', $copy->id()->toRfc4122(), null, json_encode(['sourceId' => $projectId], \JSON_THROW_ON_ERROR));

            return new ProjectMutationResult($copy, $preview);
        });
    }
}
