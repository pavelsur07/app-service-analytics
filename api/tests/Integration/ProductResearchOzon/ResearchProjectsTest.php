<?php

declare(strict_types=1);

namespace App\Tests\Integration\ProductResearchOzon;

use App\ProductResearchOzon\Application\AddPeriodsInput;
use App\ProductResearchOzon\Application\AddResearchPeriodsAction;
use App\ProductResearchOzon\Application\CopyProjectInput;
use App\ProductResearchOzon\Application\CopyResearchProjectAction;
use App\ProductResearchOzon\Application\CreateProjectInput;
use App\ProductResearchOzon\Application\CreateResearchProjectAction;
use App\ProductResearchOzon\Domain\CalendarSelection;
use App\ProductResearchOzon\Domain\PeriodRange;
use App\ProductResearchOzon\Domain\ResearchProblem;
use App\ProductResearchOzon\Domain\ResearchProjectRepository;
use App\ProductResearchOzon\Infrastructure\Query\ResearchProjectsQuery;
use App\Tests\Support\Builder\ResearchProjectBuilder;
use App\Tests\Support\Fake\ResearchCategories;
use App\Tests\Support\ProductResearchOzon\ResearchServices;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ResearchProjectsTest extends KernelTestCase
{
    use ResearchServices;

    public function testCreateAddNoopCopyAndAuditAreAtomic(): void
    {
        self::bootKernel();
        $company = Uuid::v7()->toRfc4122();
        $actor = Uuid::v7()->toRfc4122();
        $catalog = self::service(ResearchCategories::class);
        $catalog->publish($company, ['Одежда/Хиджабы', 'Другая категория']);
        $create = self::service(CreateResearchProjectAction::class);
        $result = $create($company, new CreateProjectInput('Одежда/Хиджабы', new CalendarSelection([new PeriodRange('2026-01', '2026-03')]), false, '2026-04-15'), $actor);
        $id = $result->project->id()->toRfc4122();
        $query = self::service(ResearchProjectsQuery::class);
        $view = $query->detail($company, $id);
        self::assertNotNull($view);
        self::assertSame($actor, $view->project->createdByUserId);
        self::assertSame('Хиджабы · янв–мар 2026', $view->project->name);
        self::assertSame(1, $view->project->version);
        $add = self::service(AddResearchPeriodsAction::class);
        $input = new AddPeriodsInput([new PeriodRange('2026-03', '2026-04')], 1, '2026-04-15', true);
        $result = $add($company, $id, $input, $actor);
        self::assertSame(2, $result->project->version());
        self::assertCount(4, $result->preview->months);
        self::assertSame(14, $result->preview->months[3]->days);
        $result = $add($company, $id, new AddPeriodsInput($input->periods, 2, '2026-04-15'), $actor);
        self::assertSame(2, $result->project->version());
        $copy = self::service(CopyResearchProjectAction::class);
        $nextActor = Uuid::v7()->toRfc4122();
        $copied = $copy($company, $id, new CopyProjectInput(2, '2026-04-15', 'Другая категория', 'Копия'), $nextActor);
        self::assertNotEquals($id, $copied->project->id()->toRfc4122());
        self::assertSame(1, $copied->project->version());
        $copiedView = $query->detail($company, $copied->project->id()->toRfc4122());
        self::assertNotNull($copiedView);
        self::assertSame($nextActor, $copiedView->project->createdByUserId);
        self::assertCount(2, $copiedView->periods);
        self::assertSame('Другая категория', $copiedView->project->categoryPath);
        self::assertSame('Хиджабы · янв–мар 2026', $query->detail($company, $id)?->project->name);
        $db = self::service(Connection::class);
        self::assertSame(3, $db->fetchOne('SELECT COUNT(*) FROM audit_record WHERE company_id = :company', ['company' => $company]));
        foreach ($db->fetchFirstColumn('SELECT new_value FROM audit_record WHERE company_id = :company', ['company' => $company]) as $value) {
            self::assertIsString($value);
            self::assertStringNotContainsString('Комментарий', $value);
        }
    }

    public function testListDetailAndCursorStayWithinCompany(): void
    {
        self::bootKernel();
        $repository = self::service(ResearchProjectRepository::class);
        $query = self::service(ResearchProjectsQuery::class);
        $company = Uuid::v7();
        $first = ResearchProjectBuilder::aProject()->withCompanyId($company)->persistWith($repository);
        $second = ResearchProjectBuilder::aProject()->withCompanyId($company)->persistWith($repository);
        $foreign = ResearchProjectBuilder::aProject()->persistWith($repository);
        self::assertNull($query->detail($company->toRfc4122(), $foreign->id()->toRfc4122()));
        $rows = $query->page($company->toRfc4122(), null, 1);
        self::assertCount(2, $rows);
        self::assertSame($second->id()->toRfc4122(), $rows[0]->id);
        ResearchProjectBuilder::aProject()->withCompanyId($company)->persistWith($repository);
        $rows = $query->page($company->toRfc4122(), $second->id()->toRfc4122(), 1);
        self::assertCount(1, $rows);
        self::assertSame($first->id()->toRfc4122(), $rows[0]->id);
        self::assertSame([], $query->page(Uuid::v7()->toRfc4122(), $foreign->id()->toRfc4122(), 50));
    }

    public function testStalePreviewAndInvalidCategoryDoNotWrite(): void
    {
        self::bootKernel();
        $company = Uuid::v7()->toRfc4122();
        self::service(ResearchCategories::class)->publish($company, []);
        $create = self::service(CreateResearchProjectAction::class);
        foreach (['2026-04-14' => ResearchProblem::class, '2026-04-15' => \InvalidArgumentException::class] as $date => $exception) {
            try {
                $create($company, new CreateProjectInput('Неизвестная', new CalendarSelection([new PeriodRange('2026-01', '2026-01')]), false, $date), Uuid::v7()->toRfc4122());
                self::fail('must reject');
            } catch (ResearchProblem|\InvalidArgumentException $error) {
                self::assertInstanceOf($exception, $error);
            }
        }
        self::assertSame([], self::service(ResearchProjectsQuery::class)->page($company, null, 50));
    }

    public function testFailureAfterFlushRollsBackProjectPeriodsAndAudit(): void
    {
        self::bootKernel();
        $repository = self::service(ResearchProjectRepository::class);
        $project = ResearchProjectBuilder::aProject()->build();
        $company = $project->companyId()->toRfc4122();
        try {
            $repository->transactional(static function () use ($repository, $project, $company): void {
                $repository->add($project, [new PeriodRange('2026-01', '2026-01')]);
                self::service(\App\Identity\Application\Facade\IdentityFacade::class)->recordAuditEntry($company, Uuid::v7()->toRfc4122(), 'product_research_ozon.project_created', $project->id()->toRfc4122(), null, '{}');
                self::service(\Doctrine\ORM\EntityManagerInterface::class)->flush();
                throw new \RuntimeException('synthetic_failure');
            });
        } catch (\RuntimeException $failure) {
            self::assertSame('synthetic_failure', $failure->getMessage());
        }
        self::assertNull(self::service(ResearchProjectsQuery::class)->detail($company, $project->id()->toRfc4122()));
        foreach (['product_research_ozon_project', 'product_research_ozon_period', 'audit_record'] as $table) {
            self::assertSame(0, self::service(Connection::class)->fetchOne('SELECT COUNT(*) FROM '.$table.' WHERE company_id = :company', ['company' => $company]));
        }
    }

    public function testForeignKeyRejectsCrossCompanyPeriod(): void
    {
        self::bootKernel();
        $project = ResearchProjectBuilder::aProject()->persistWith(self::service(ResearchProjectRepository::class));
        $this->expectException(ForeignKeyConstraintViolationException::class);
        self::service(Connection::class)->executeStatement("INSERT INTO product_research_ozon_period (id, company_id, project_id, from_month, to_month, name, position) VALUES (:id, :company, :project, '2026-01-01', '2026-02-01', '', 1)", ['id' => Uuid::v7()->toRfc4122(), 'company' => Uuid::v7()->toRfc4122(), 'project' => $project->id()->toRfc4122()]);
    }

    public function testDatabaseRejectsDuplicateRange(): void
    {
        self::bootKernel();
        $project = ResearchProjectBuilder::aProject()->persistWith(self::service(ResearchProjectRepository::class));
        $this->expectException(UniqueConstraintViolationException::class);
        self::service(Connection::class)->executeStatement('INSERT INTO product_research_ozon_period (id, company_id, project_id, from_month, to_month, name, position) SELECT :id, company_id, project_id, from_month, to_month, name, position FROM product_research_ozon_period WHERE company_id = :company AND project_id = :project', ['id' => Uuid::v7()->toRfc4122(), 'company' => $project->companyId()->toRfc4122(), 'project' => $project->id()->toRfc4122()]);
    }
}
