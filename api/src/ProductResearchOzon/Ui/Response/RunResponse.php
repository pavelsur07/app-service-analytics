<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\Response;

use App\ProductResearchOzon\Domain\ValueObject\RunMonthState;
use App\ProductResearchOzon\Domain\ValueObject\RunView;
use OpenApi\Attributes as OA;

final readonly class RunResponse
{
    public string $id;
    public string $projectId;
    #[OA\Property(enum: ['queued', 'running', 'ready', 'error'])]
    public string $status;
    public int $revision;
    public bool $delivered;
    public string $createdAt;
    /** @var list<RunMonthResponse> */
    public array $months;

    public function __construct(RunView $view)
    {
        $this->id = $view->progress->runId;
        $this->projectId = $view->projectId;
        $this->status = $view->progress->status;
        $this->revision = $view->progress->revision;
        $this->delivered = $view->delivered;
        $this->createdAt = $view->createdAt->format(\DATE_ATOM);
        $this->months = array_map(static fn (RunMonthState $month): RunMonthResponse => new RunMonthResponse($month), $view->progress->months);
    }
}
