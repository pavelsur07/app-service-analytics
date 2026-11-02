<?php

declare(strict_types=1);

namespace App\Tests\Integration\ProductResearchOzon;

use App\Identity\Application\Facade\IdentityFacade;
use App\ProductResearchOzon\Application\GetResearchRunAction;
use App\ProductResearchOzon\Application\ResearchPreparation;
use App\ProductResearchOzon\Application\StartResearchRunAction;
use App\ProductResearchOzon\Domain\ResearchProjectRepository;
use App\ProductResearchOzon\Domain\ResearchRunRepository;
use App\ProductResearchOzon\Domain\RunDeliveryRepository;
use App\ProductResearchOzon\Domain\RunNotifier;
use App\ProductResearchOzon\Domain\ValueObject\RunMonthState;
use App\ProductResearchOzon\Domain\ValueObject\RunProgress;
use App\ProductResearchOzon\Domain\ValueObject\RunProvenance;
use App\ProductResearchOzon\Infrastructure\Config\CatalogContext;
use App\ProductResearchOzon\Infrastructure\Query\ProjectProgressQuery;
use App\ProductResearchOzon\Infrastructure\Query\ResearchRunsQuery;
use App\Tests\Support\Builder\ResearchProjectBuilder;
use App\Tests\Support\ProductResearchOzon\ResearchServices;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ProjectProgressTest extends KernelTestCase
{
    use ResearchServices;

    public function testProjectionIncludesReadyHistoryAndMissingRequirementsWithinCompany(): void
    {
        self::bootKernel();
        $project = ResearchProjectBuilder::aProject()->persistWith(self::service(ResearchProjectRepository::class));
        $company = $project->companyId()->toRfc4122();
        $id = $project->id()->toRfc4122();
        $source = Uuid::v7()->toRfc4122();
        $notifier = new class implements RunNotifier {
            public function notify(string $companyId, string $runId): void
            {
            }
        };
        $start = new StartResearchRunAction(self::service(ResearchProjectRepository::class), self::service(ResearchRunRepository::class), self::service(ResearchRunsQuery::class), self::service(ResearchPreparation::class), new CatalogContext($source), self::service(IdentityFacade::class), $notifier, self::service(GetResearchRunAction::class));
        $first = $start($company, $id, Uuid::v7()->toRfc4122(), 1, '2026-04-15', Uuid::v7()->toRfc4122());
        $query = new ProjectProgressQuery(self::service(Connection::class));
        self::assertNull($query::map($query->forProject(Uuid::v7()->toRfc4122(), $id)->executeQuery()->fetchAllAssociative()));
        $pending = $query::map($query->forProject($company, $id)->executeQuery()->fetchAllAssociative());
        self::assertNotNull($pending);
        self::assertSame('loading', $pending->status);
        self::assertSame($first->progress->runId, $pending->lastRunId);
        self::assertCount(3, $pending->months);
        $months = array_map(static fn (RunMonthState $m): RunMonthState => '2026-03' === $m->period->month ? new RunMonthState($m->period, 'error', null, null, 'credentials_rejected') : new RunMonthState($m->period, 'ready', Uuid::v7()->toRfc4122(), 0, null, new RunProvenance('api', new \DateTimeImmutable('2026-04-01'), null)), $first->progress->months);
        $repo = self::service(RunDeliveryRepository::class);
        $owner = Uuid::v7()->toRfc4122();
        $at = new \DateTimeImmutable('2026-04-15T09:00:01Z');
        self::assertNotNull($repo->claimDelivery($company, $first->progress->runId, $owner, $at));
        self::assertTrue($repo->delivered($company, $first->progress->runId, $owner, $at));
        self::assertNotNull($repo->claimPoll($company, $first->progress->runId, $owner, $at));
        self::assertTrue($repo->apply($company, $first->progress->runId, $owner, new RunProgress($company, $first->progress->runId, $source, 'error', 9, $months), $at));
        $retry = $start($company, $id, Uuid::v7()->toRfc4122(), 2, '2026-04-15', Uuid::v7()->toRfc4122());
        $progress = $query::map($query->forProject($company, $id)->executeQuery()->fetchAllAssociative());
        self::assertNotNull($progress);
        self::assertCount(3, $progress->months);
        self::assertSame($retry->progress->runId, $progress->lastRunId);
        self::assertSame('ready', $progress->months[0]->attempt?->status);
        self::assertSame(0, $progress->months[0]->attempt->rowCount);
        self::assertNull($progress->months[0]->attempt->provenance?->usedAt);
        self::assertSame('queued', $progress->months[2]->attempt?->status);
        $estimate = new \App\ProductResearchOzon\Infrastructure\Query\ResearchCacheEstimateQuery(self::service(Connection::class));
        $periods = array_map(static fn (RunMonthState $m): \App\ProductResearchOzon\Domain\MonthSlice => $m->period, $first->progress->months);
        self::assertSame(2, $estimate->months($company, $source, $project->categoryPath(), $periods)->executeQuery()->fetchOne());
        self::assertSame(0, $estimate->months(Uuid::v7()->toRfc4122(), $source, $project->categoryPath(), $periods)->executeQuery()->fetchOne());
        self::assertSame(0, $estimate->months($company, Uuid::v7()->toRfc4122(), $project->categoryPath(), $periods)->executeQuery()->fetchOne());
        self::assertSame(0, $estimate->months($company, $source, 'Другая категория', $periods)->executeQuery()->fetchOne());
    }

    private function start(string $source): StartResearchRunAction
    {
        $notifier = new class implements RunNotifier {
            public function notify(string $companyId, string $runId): void
            {
            }
        };

        return new StartResearchRunAction(self::service(ResearchProjectRepository::class), self::service(ResearchRunRepository::class), self::service(ResearchRunsQuery::class), self::service(ResearchPreparation::class), new CatalogContext($source), self::service(IdentityFacade::class), $notifier, self::service(GetResearchRunAction::class));
    }

    public function testNewPartialRequirementDoesNotShowOldReadyAndOtherSourceStartsFresh(): void
    {
        self::bootKernel();
        $project = ResearchProjectBuilder::aProject()->withPeriods([new \App\ProductResearchOzon\Domain\PeriodRange('2026-01', '2026-04')], true)->persistWith(self::service(ResearchProjectRepository::class));
        $company = $project->companyId()->toRfc4122();
        $id = $project->id()->toRfc4122();
        $source = Uuid::v7()->toRfc4122();
        $view = ($this->start($source))($company, $id, Uuid::v7()->toRfc4122(), 1, '2026-04-15', Uuid::v7()->toRfc4122());
        $ready = array_map(static fn (RunMonthState $m): RunMonthState => new RunMonthState($m->period, 'ready', Uuid::v7()->toRfc4122(), 1, null), $view->progress->months);
        $repo = self::service(RunDeliveryRepository::class);
        $owner = Uuid::v7()->toRfc4122();
        $at = new \DateTimeImmutable('2026-04-15T09:00:01Z');
        $repo->claimDelivery($company, $view->progress->runId, $owner, $at);
        $repo->delivered($company, $view->progress->runId, $owner, $at);
        $repo->claimPoll($company, $view->progress->runId, $owner, $at);
        self::assertTrue($repo->apply($company, $view->progress->runId, $owner, new RunProgress($company, $view->progress->runId, $source, 'ready', 9, $ready), $at));
        self::service(\App\Tests\Support\Fake\ResearchClock::class)->freeze('2026-04-16T09:00:00Z');
        (self::service(\App\ProductResearchOzon\Application\AddResearchPeriodsAction::class))($company, $id, new \App\ProductResearchOzon\Application\AddPeriodsInput([new \App\ProductResearchOzon\Domain\PeriodRange('2026-02', '2026-02')], 2, '2026-04-16'), Uuid::v7()->toRfc4122());
        $query = new ProjectProgressQuery(self::service(Connection::class));
        $projection = $query::map($query->forProject($company, $id)->executeQuery()->fetchAllAssociative());
        self::assertNotNull($projection);
        self::assertSame('partially_loaded', $projection->status);
        self::assertSame('2026-04-15', $projection->months[3]->period->dateTo);
        self::assertNull($projection->months[3]->attempt);
        $estimate = new \App\ProductResearchOzon\Infrastructure\Query\ResearchCacheEstimateQuery(self::service(Connection::class));
        self::assertSame(0, $estimate->months($company, $source, $project->categoryPath(), [$projection->months[3]->period])->executeQuery()->fetchOne());
        $next = ($this->start(Uuid::v7()->toRfc4122()))($company, $id, Uuid::v7()->toRfc4122(), 3, '2026-04-16', Uuid::v7()->toRfc4122());
        self::assertCount(4, $next->progress->months);
        $projection = $query::map($query->forProject($company, $id)->executeQuery()->fetchAllAssociative());
        self::assertNotNull($projection);
        self::assertSame(['queued', 'queued', 'queued', 'queued'], array_map(static fn ($m): ?string => $m->attempt?->status, $projection->months));
    }
}
