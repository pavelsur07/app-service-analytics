<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Application;

use App\Identity\Application\Facade\IdentityFacade;
use App\ProductResearchOzon\Domain\CalendarSelection;
use App\ProductResearchOzon\Domain\PeriodRange;
use App\ProductResearchOzon\Domain\ResearchProjectRepository;

final readonly class AddResearchPeriodsAction
{
    public function __construct(private ResearchProjectRepository $projects, private ResearchPreparation $prepare, private IdentityFacade $identity)
    {
    }

    public function __invoke(string $companyId, string $projectId, AddPeriodsInput $input, string $actor): ProjectMutationResult
    {
        return $this->projects->transactional(function () use ($companyId, $projectId, $input, $actor): ProjectMutationResult {
            $project = $this->projects->locked($companyId, $projectId, $input->expectedVersion);
            $old = $this->projects->periods($companyId, $projectId);
            $keys = array_fill_keys(array_map(static fn (PeriodRange $range): string => $range->key(), $old), true);
            $new = [];
            foreach ($input->periods as $range) {
                if (!isset($keys[$range->key()])) {
                    $new[] = $range;
                    $keys[$range->key()] = true;
                }
            }
            $flag = $input->includeCurrentMonth ?? $project->includeCurrentMonth();
            $at = $this->prepare->now();
            $preview = $this->prepare->preview(new CalendarSelection([...$old, ...$new]), $flag, $at, $input->previewCalendarDate);
            if ([] === $new && $flag === $project->includeCurrentMonth()) {
                return new ProjectMutationResult($project, $preview);
            }
            $previousValue = self::auditValue($old, $project->includeCurrentMonth());
            $project->recordPeriodsChanged(\count($preview->periods), $flag, $at);
            $this->projects->append($project, $new, \count($old));
            $this->identity->recordAuditEntry($companyId, $actor, 'product_research_ozon.periods_added', $projectId, $previousValue, self::auditValue($preview->periods, $flag));

            return new ProjectMutationResult($project, $preview);
        });
    }

    /** @param list<PeriodRange> $periods */
    private static function auditValue(array $periods, bool $includeCurrentMonth): string
    {
        return json_encode(['includeCurrentMonth' => $includeCurrentMonth, 'periods' => array_map(static fn (PeriodRange $period): array => ['fromMonth' => $period->fromMonth, 'toMonth' => $period->toMonth, 'name' => $period->name], $periods)], \JSON_THROW_ON_ERROR);
    }
}
