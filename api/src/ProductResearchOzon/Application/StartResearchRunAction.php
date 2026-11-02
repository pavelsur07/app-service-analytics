<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Application;

use App\Identity\Application\Facade\IdentityFacade;
use App\ProductResearchOzon\Domain\CalendarSelection;
use App\ProductResearchOzon\Domain\CatalogSyncFailure;
use App\ProductResearchOzon\Domain\MonthSlice;
use App\ProductResearchOzon\Domain\ResearchProblem;
use App\ProductResearchOzon\Domain\ResearchProjectRepository;
use App\ProductResearchOzon\Domain\ResearchRun;
use App\ProductResearchOzon\Domain\ResearchRunRepository;
use App\ProductResearchOzon\Domain\RunNotifier;
use App\ProductResearchOzon\Domain\ValueObject\RunProgress;
use App\ProductResearchOzon\Domain\ValueObject\RunView;
use App\ProductResearchOzon\Infrastructure\Config\CatalogContext;
use App\ProductResearchOzon\Infrastructure\Query\ResearchRunsQuery;
use Symfony\Component\Uid\Uuid;

final readonly class StartResearchRunAction
{
    public function __construct(private ResearchProjectRepository $projects, private ResearchRunRepository $runs, private ResearchRunsQuery $query, private ResearchPreparation $prepare, private CatalogContext $context, private IdentityFacade $identity, private RunNotifier $notifier, private GetResearchRunAction $get)
    {
    }

    public function __invoke(string $companyId, string $projectId, string $requestId, int $expectedVersion, string $calendarDate, string $actor): RunView
    {
        if (!RunProgress::validId($requestId) || $expectedVersion < 1 || 1 !== preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}$/D', $calendarDate)) {
            throw new \InvalidArgumentException('invalid_request');
        }
        $hash = hash('sha256', json_encode([$expectedVersion, $calendarDate], \JSON_THROW_ON_ERROR));
        $runId = $this->projects->transactional(function () use ($companyId, $projectId, $requestId, $expectedVersion, $calendarDate, $actor, $hash): string {
            $project = $this->projects->lockedForRun($companyId, $projectId);
            $existing = $this->runs->byRequest($companyId, $projectId, $requestId);
            if (null !== $existing) {
                if ($existing['inputHash'] !== $hash) {
                    throw new ResearchProblem('idempotency_conflict');
                }

                return $existing['id'];
            }
            if ($project->version() !== $expectedVersion) {
                throw new ResearchProblem('version_conflict');
            }
            $at = $this->prepare->now();
            $preview = $this->prepare->preview(new CalendarSelection($this->projects->periods($companyId, $projectId)), $project->includeCurrentMonth(), $at, $calendarDate);
            try {
                $source = $this->context->sourceId();
            } catch (CatalogSyncFailure) {
                throw new ResearchProblem('catalog_not_ready');
            }
            $ready = [];
            foreach ($this->query->readyMonths($companyId, $projectId, $source)->executeQuery()->fetchAllAssociative() as $row) {
                $ready[ResearchRunsQuery::text($row, 'month')] = [ResearchRunsQuery::text($row, 'date_from'), ResearchRunsQuery::text($row, 'date_to')];
            }
            $months = array_values(array_filter($preview->months, static fn (MonthSlice $month): bool => $month->partial || ($ready[$month->month] ?? null) !== [$month->dateFrom, $month->dateTo]));
            if ([] === $months) {
                throw new ResearchProblem('no_months_to_load');
            }
            $run = new ResearchRun($project, Uuid::fromString($source), Uuid::fromString($requestId), $hash, $months, Uuid::fromString($actor), $at);
            $this->runs->add($run, $months);
            $project->freezeRequirements($preview->months);
            $project->recordRunRequested($run->id(), $at);
            $this->identity->recordAuditEntry($companyId, $actor, 'product_research_ozon.run_requested', $run->id()->toRfc4122(), null, json_encode(['monthCount' => \count($months)], \JSON_THROW_ON_ERROR));

            return $run->id()->toRfc4122();
        });
        $this->notifier->notify($companyId, $runId);

        return ($this->get)($companyId, $projectId, $runId);
    }
}
