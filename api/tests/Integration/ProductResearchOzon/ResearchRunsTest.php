<?php

declare(strict_types=1);

namespace App\Tests\Integration\ProductResearchOzon;

use App\Identity\Application\Facade\IdentityFacade;
use App\ProductResearchOzon\Application\GetResearchRunAction;
use App\ProductResearchOzon\Application\ResearchPreparation;
use App\ProductResearchOzon\Application\StartResearchRunAction;
use App\ProductResearchOzon\Domain\ResearchProblem;
use App\ProductResearchOzon\Domain\ResearchProject;
use App\ProductResearchOzon\Domain\ResearchProjectRepository;
use App\ProductResearchOzon\Domain\ResearchRunRepository;
use App\ProductResearchOzon\Domain\RunDeliveryRepository;
use App\ProductResearchOzon\Domain\RunNotifier;
use App\ProductResearchOzon\Domain\RunSyncFailure;
use App\ProductResearchOzon\Domain\ValueObject\RunMonthState;
use App\ProductResearchOzon\Domain\ValueObject\RunProgress;
use App\ProductResearchOzon\Infrastructure\Config\CatalogContext;
use App\ProductResearchOzon\Infrastructure\Query\ResearchRunsQuery;
use App\Tests\Support\Builder\ResearchProjectBuilder;
use App\Tests\Support\Builder\ResearchRunBuilder;
use App\Tests\Support\Builder\ResearchRunMonthBuilder;
use App\Tests\Support\Fake\ResearchClock;
use App\Tests\Support\ProductResearchOzon\ResearchServices;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ResearchRunsTest extends KernelTestCase
{
    use ResearchServices;

    /** @return array{ResearchProject, string, StartResearchRunAction, RunNotifier} */
    private function fixture(): array
    {
        self::bootKernel();
        $project = ResearchProjectBuilder::aProject()->persistWith(self::service(ResearchProjectRepository::class));
        $source = Uuid::v7()->toRfc4122();
        $notifier = new class implements RunNotifier {
            /** @var list<string> */
            public array $ids = [];

            public function notify(string $companyId, string $runId): void
            {
                $this->ids[] = $runId;
            }
        };
        $start = new StartResearchRunAction(self::service(ResearchProjectRepository::class), self::service(ResearchRunRepository::class), self::service(ResearchRunsQuery::class), self::service(ResearchPreparation::class), new CatalogContext($source), self::service(IdentityFacade::class), $notifier, self::service(GetResearchRunAction::class));

        return [$project, $source, $start, $notifier];
    }

    public function testRunAndOutboxAreCommittedTogetherAndRetrySurvivesVersionAndMidnight(): void
    {
        [$project, , $start] = $this->fixture();
        $company = $project->companyId()->toRfc4122();
        $id = $project->id()->toRfc4122();
        $request = Uuid::v7()->toRfc4122();
        $actor = Uuid::v7()->toRfc4122();
        $first = $start($company, $id, $request, 1, '2026-04-15', $actor);
        self::assertFalse($first->delivered);
        self::assertCount(3, $first->progress->months);
        self::assertSame(2, $project->version());
        self::service(ResearchClock::class)->freeze('2026-04-16T09:00:00Z');
        self::assertSame($first->progress->runId, $start($company, $id, $request, 1, '2026-04-15', $actor)->progress->runId);
        self::assertSame(1, self::service(Connection::class)->fetchOne('SELECT COUNT(*) FROM product_research_ozon_run WHERE company_id = ?', [$company]));
        self::assertSame(1, self::service(Connection::class)->fetchOne('SELECT COUNT(*) FROM audit_record WHERE company_id = ?', [$company]));
        $this->expectException(ResearchProblem::class);
        $this->expectExceptionMessage('idempotency_conflict');
        $start($company, $id, $request, 2, '2026-04-16', $actor);
    }

    public function testSeparateActiveRunIsRejectedByDatabaseAndTransactionRolledBack(): void
    {
        [$project, , $start] = $this->fixture();
        $company = $project->companyId()->toRfc4122();
        $id = $project->id()->toRfc4122();
        $actor = Uuid::v7()->toRfc4122();
        $first = $start($company, $id, Uuid::v7()->toRfc4122(), 1, '2026-04-15', $actor);
        try {
            $start($company, $id, Uuid::v7()->toRfc4122(), 2, '2026-04-15', $actor);
            self::fail('active uniqueness missing');
        } catch (ResearchProblem $error) {
            self::assertSame('version_conflict', $error->reason);
        }
        self::assertSame($first->progress->runId, self::service(Connection::class)->fetchOne('SELECT id FROM product_research_ozon_run WHERE company_id = ? LIMIT 1', [$company]));
    }

    public function testDeliveryLeaseLostAckAndMonotoneProgress(): void
    {
        [$project, $source, $start] = $this->fixture();
        $company = $project->companyId()->toRfc4122();
        $id = $project->id()->toRfc4122();
        $first = $start($company, $id, Uuid::v7()->toRfc4122(), 1, '2026-04-15', Uuid::v7()->toRfc4122());
        $run = $first->progress->runId;
        $repo = self::service(RunDeliveryRepository::class);
        $at = new \DateTimeImmutable('2026-04-15T09:00:01Z');
        $one = Uuid::v7()->toRfc4122();
        $two = Uuid::v7()->toRfc4122();
        self::assertNull($repo->claimDelivery(Uuid::v7()->toRfc4122(), $run, $one, $at));
        $delivery = $repo->claimDelivery($company, $run, $one, $at);
        self::assertNotNull($delivery);
        self::assertSame($source, $delivery->sourceId);
        self::assertNull($repo->claimDelivery($company, $run, $two, $at));
        self::service(Connection::class)->executeStatement("UPDATE product_research_ozon_run SET lease_until = clock_timestamp() - INTERVAL '1 second' WHERE company_id = ? AND id = ?", [$company, $run]);
        $again = $repo->claimDelivery($company, $run, $two, $at->modify('+1 second'));
        self::assertNotNull($again);
        self::assertSame($delivery->payload, $again->payload);
        self::assertFalse($repo->delivered($company, $run, $one, $at));
        self::assertTrue($repo->delivered($company, $run, $two, $at));
        self::assertSame($source, $repo->claimPoll($company, $run, $two, $at));
        $ready = array_map(static fn (RunMonthState $month): RunMonthState => new RunMonthState($month->period, 'ready', Uuid::v7()->toRfc4122(), 0, null), $first->progress->months);
        self::assertTrue($repo->apply($company, $run, $two, new RunProgress($company, $run, $source, 'ready', 9, $ready), $at));
        $view = (self::service(GetResearchRunAction::class))($company, $id, $run);
        self::assertTrue($view->delivered);
        self::assertSame('ready', $view->progress->status);
        self::assertNull($repo->claimPoll($company, $run, $one, $at->modify('+1 hour')));
        self::assertSame([], self::service(ResearchRunsQuery::class)->due($company, true, $at)->executeQuery()->fetchAllAssociative());
        $this->expectException(ResearchProblem::class);
        $this->expectExceptionMessage('no_months_to_load');
        $start($company, $id, Uuid::v7()->toRfc4122(), 2, '2026-04-15', Uuid::v7()->toRfc4122());
    }

    public function testConflictingRevisionAndSourceDoNotOverwriteLocalProgress(): void
    {
        [$project, $source, $start] = $this->fixture();
        $company = $project->companyId()->toRfc4122();
        $id = $project->id()->toRfc4122();
        $view = $start($company, $id, Uuid::v7()->toRfc4122(), 1, '2026-04-15', Uuid::v7()->toRfc4122());
        $run = $view->progress->runId;
        $repo = self::service(RunDeliveryRepository::class);
        $at = new \DateTimeImmutable('2026-04-15T09:00:01Z');
        $owner = Uuid::v7()->toRfc4122();
        $repo->claimDelivery($company, $run, $owner, $at);
        $repo->delivered($company, $run, $owner, $at);
        $repo->claimPoll($company, $run, $owner, $at);
        foreach ([new RunProgress($company, $run, Uuid::v7()->toRfc4122(), 'queued', 1, $view->progress->months), new RunProgress($company, $run, $source, 'running', 1, $view->progress->months)] as $bad) {
            try {
                $repo->apply($company, $run, $owner, $bad, $at);
                self::fail('invalid progress accepted');
            } catch (RunSyncFailure) {
                self::assertSame('queued', (self::service(GetResearchRunAction::class))($company, $id, $run)->progress->status);
            }
        }
        self::assertTrue($repo->apply($company, $run, $owner, $view->progress, $at));
    }

    public function testBuildersPersistMappedCompositeRelationships(): void
    {
        [$project, $source] = $this->fixture();
        $em = self::service(EntityManagerInterface::class);
        $run = ResearchRunBuilder::aRun()->withProject($project)->withSource(Uuid::fromString($source))->build();
        $em->persist($run);
        $em->persist(ResearchRunMonthBuilder::aMonth()->withRun($run)->build());
        $em->flush();
        self::assertCount(1, (self::service(GetResearchRunAction::class))($project->companyId()->toRfc4122(), $project->id()->toRfc4122(), $run->id()->toRfc4122())->progress->months);
    }

    public function testRetryRunContainsOnlyFailedMonthsAndKeepsReadyHistory(): void
    {
        [$project, $source, $start] = $this->fixture();
        $company = $project->companyId()->toRfc4122();
        $id = $project->id()->toRfc4122();
        $actor = Uuid::v7()->toRfc4122();
        $first = $start($company, $id, Uuid::v7()->toRfc4122(), 1, '2026-04-15', $actor);
        $run = $first->progress->runId;
        $repo = self::service(RunDeliveryRepository::class);
        $at = new \DateTimeImmutable('2026-04-15T09:00:01Z');
        $owner = Uuid::v7()->toRfc4122();
        $repo->claimDelivery($company, $run, $owner, $at);
        $repo->delivered($company, $run, $owner, $at);
        $repo->claimPoll($company, $run, $owner, $at);
        $months = [];
        foreach ($first->progress->months as $index => $month) {
            $months[] = 2 === $index ? new RunMonthState($month->period, 'error', null, null, 'retry_exhausted') : new RunMonthState($month->period, 'ready', Uuid::v7()->toRfc4122(), 1, null);
        }
        self::assertTrue($repo->apply($company, $run, $owner, new RunProgress($company, $run, $source, 'error', 9, $months), $at));
        $retry = $start($company, $id, Uuid::v7()->toRfc4122(), 2, '2026-04-15', $actor);
        self::assertSame(['2026-03'], array_map(static fn (RunMonthState $month): string => $month->period->month, $retry->progress->months));
        self::assertSame($months[0]->snapshotId, (self::service(GetResearchRunAction::class))($company, $id, $run)->progress->months[0]->snapshotId);
    }

    public function testRepairPicksLostNotificationAndLostServiceAckThenPollsProgress(): void
    {
        [$project, $source, $start] = $this->fixture();
        $company = $project->companyId()->toRfc4122();
        $view = $start($company, $project->id()->toRfc4122(), Uuid::v7()->toRfc4122(), 1, '2026-04-15', Uuid::v7()->toRfc4122());
        $clock = self::service(ResearchClock::class);
        $remote = new \App\Tests\Support\Fake\ResearchRunSource(new RunProgress($company, $view->progress->runId, $source, 'ready', 7, array_map(static fn (RunMonthState $month): RunMonthState => new RunMonthState($month->period, 'ready', Uuid::v7()->toRfc4122(), 1, null), $view->progress->months)));
        $repo = self::service(RunDeliveryRepository::class);
        $process = new \App\ProductResearchOzon\Application\ProcessResearchRunsAction(self::service(ResearchRunsQuery::class), new \App\ProductResearchOzon\Application\RelayResearchRunAction($repo, $remote, $clock), new \App\ProductResearchOzon\Application\SynchronizeResearchRunAction($repo, $remote, $clock), $clock);
        $process($company);
        self::assertSame(1, $remote->submissions);
        $process($company);
        self::assertSame(1, $remote->submissions);
        $remote->loseAck = false;
        $clock->freeze('2026-04-15T10:00:00Z');
        $process($company);
        self::assertSame(2, $remote->submissions);
        self::assertSame('ready', (self::service(GetResearchRunAction::class))($company, $project->id()->toRfc4122(), $view->progress->runId)->progress->status);
    }
}
