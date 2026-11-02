<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\Response;

use App\ProductResearchOzon\Infrastructure\Query\ProjectMonthRow;
use App\ProductResearchOzon\Infrastructure\Query\ProjectProgressView;
use OpenApi\Attributes as OA;

final readonly class ProjectProgressResponse
{
    public string $projectId;
    public int $version;
    #[OA\Property(enum: ['draft', 'loading', 'loaded', 'partially_loaded', 'error'])]
    public string $status;
    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $lastRunId;
    public ?int $runRevision;
    /** @var list<ProjectMonthResponse> */
    public array $months;

    public function __construct(ProjectProgressView $view)
    {
        $this->projectId = $view->projectId;
        $this->version = $view->version;
        $this->status = $view->status;
        $this->lastRunId = $view->lastRunId;
        $this->runRevision = $view->runRevision;
        $this->months = array_map(static fn (ProjectMonthRow $m): ProjectMonthResponse => new ProjectMonthResponse($m), $view->months);
    }
}
