<?php

declare(strict_types=1);

use App\Kernel;
use App\ProductResearchOzon\Application\AddPeriodsInput;
use App\ProductResearchOzon\Application\AddResearchPeriodsAction;
use App\ProductResearchOzon\Application\CopyProjectInput;
use App\ProductResearchOzon\Application\CopyResearchProjectAction;
use App\ProductResearchOzon\Domain\CatalogProjectionRepository;
use App\ProductResearchOzon\Domain\PeriodRange;
use App\ProductResearchOzon\Domain\ResearchProblem;
use App\ProductResearchOzon\Domain\ResearchProjectRepository;
use App\Tests\Support\Builder\ResearchProjectBuilder;
use App\Tests\Support\Fake\ResearchCategories;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 3).'/vendor/autoload.php';

/** @param array<string,mixed> $data */
function researchEvent(array $data): void
{
    fwrite(\STDOUT, json_encode($data, \JSON_THROW_ON_ERROR)."\n");
    fflush(\STDOUT);
}

try {
    if ('test' !== getenv('APP_ENV')) {
        throw new RuntimeException('test_only');
    }
    $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'test';
    (new Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');
    $line = fgets(\STDIN);
    $request = false === $line ? null : json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
    if (!is_array($request) || !is_string($request['database'] ?? null) || !is_string($request['company'] ?? null) || !Uuid::isValid($request['company']) || !is_string($request['operation'] ?? null)) {
        throw new RuntimeException('invalid_test_request');
    }
    $kernel = new Kernel('test', true);
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    if (!$container instanceof ContainerInterface) {
        throw new RuntimeException('missing_test_container');
    }
    $em = $container->get(EntityManagerInterface::class);
    if (!$em instanceof EntityManagerInterface) {
        throw new RuntimeException('missing_entity_manager');
    }
    $db = $em->getConnection();
    $database = $db->fetchOne('SELECT current_database()');
    if (!is_string($database) || $database !== $request['database'] || 1 !== preg_match('/^app_test(?:[1-9][0-9]*)?$/D', $database)) {
        throw new RuntimeException('wrong_test_database');
    }
    $company = $request['company'];
    $marker = 'stage2 concurrency '.$company;
    if (false !== $db->fetchOne('SELECT id FROM company WHERE id = :id', ['id' => $company]) || 0 !== $db->fetchOne('SELECT COUNT(*) FROM product_research_ozon_project WHERE company_id = :id AND name <> :marker', ['id' => $company, 'marker' => $marker])) {
        throw new RuntimeException('nonsynthetic_company');
    }
    $db->executeStatement("SET lock_timeout = '10s'");
    $db->executeStatement("SET statement_timeout = '15s'");
    $repository = $container->get(ResearchProjectRepository::class);
    $categories = $container->get(ResearchCategories::class);
    if (!$repository instanceof ResearchProjectRepository || !$categories instanceof ResearchCategories) {
        throw new RuntimeException('missing_test_services');
    }
    $categories->publish($company, ['Одежда/Хиджабы']);
    if ('cleanup' === $request['operation']) {
        $db->transactional(static function () use ($db, $company): void {
            $db->executeStatement('DELETE FROM product_research_ozon_category WHERE company_id=:id AND source_connection_id=:id', ['id' => $company]);
            $db->executeStatement('DELETE FROM product_research_ozon_catalog WHERE company_id=:id AND source_connection_id=:id', ['id' => $company]);
            $db->executeStatement('DELETE FROM product_research_ozon_period WHERE company_id = :id', ['id' => $company]);
            $db->executeStatement('DELETE FROM product_research_ozon_project WHERE company_id = :id', ['id' => $company]);
            $db->executeStatement("DELETE FROM audit_record WHERE company_id = :id AND action LIKE 'product_research_ozon.%'", ['id' => $company]);
        });
        researchEvent(['event' => 'result', 'outcome' => 'cleaned']);
    } elseif ('seed' === $request['operation']) {
        $project = ResearchProjectBuilder::aProject()->withCompanyId(Uuid::fromString($company))->withName($marker)->persistWith($repository);
        researchEvent(['event' => 'result', 'id' => $project->id()->toRfc4122(), 'version' => $project->version()]);
    } else {
        $id = $request['id'] ?? null;
        $version = $request['version'] ?? null;
        if (!is_string($id) || !Uuid::isValid($id) || !is_int($version) || $version < 1 || !in_array($request['operation'], ['add', 'copy', 'catalog_claim'], true)) {
            throw new RuntimeException('invalid_test_operation');
        }
        $held = true === ($request['hold'] ?? false);
        if ($held) {
            $db->beginTransaction();
        }
        researchEvent(['event' => 'started', 'pid' => $db->fetchOne('SELECT pg_backend_pid()')]);
        try {
            $outcome = 'ok';
            $result = null;
            $resultId = $id;
            $resultVersion = $version;
            if ('catalog_claim' === $request['operation']) {
                if (false === $db->fetchOne('SELECT id FROM product_research_ozon_project WHERE company_id=:company AND id=:id AND name=:marker', ['company' => $company, 'id' => $id, 'marker' => $marker])) {
                    throw new RuntimeException('missing_synthetic_project');
                }
                $cache = $container->get(CatalogProjectionRepository::class);
                if (!$cache instanceof CatalogProjectionRepository) {
                    throw new RuntimeException('missing_catalog_repository');
                }
                $outcome = $cache->claim($company, $company, Uuid::v7()->toRfc4122(), new DateTimeImmutable('now', new DateTimeZone('UTC'))) ? 'ok' : 'busy';
                $resultId = $id;
                $resultVersion = $version;
            } elseif ('add' === $request['operation']) {
                $action = $container->get(AddResearchPeriodsAction::class);
                if (!$action instanceof AddResearchPeriodsAction) {
                    throw new RuntimeException('missing_action');
                }
                $result = $action($company, $id, new AddPeriodsInput([new PeriodRange('2026-04', '2026-04')], $version, '2026-04-15', true), Uuid::v7()->toRfc4122());
            } else {
                $action = $container->get(CopyResearchProjectAction::class);
                if (!$action instanceof CopyResearchProjectAction) {
                    throw new RuntimeException('missing_action');
                }
                $result = $action($company, $id, new CopyProjectInput($version, '2026-04-15'), Uuid::v7()->toRfc4122());
            }
            if (null !== $result) {
                $resultId = $result->project->id()->toRfc4122();
                $resultVersion = $result->project->version();
            }
            researchEvent(['event' => 'prepared', 'id' => $resultId]);
            if ($held) {
                if ("release\n" !== fgets(\STDIN)) {
                    throw new RuntimeException('missing_release');
                }
                $db->commit();
            }
            researchEvent(['event' => 'result', 'outcome' => $outcome, 'id' => $resultId, 'version' => $resultVersion]);
        } catch (ResearchProblem $problem) {
            researchEvent(['event' => 'result', 'outcome' => $problem->reason]);
        }
    }
} catch (Throwable $error) {
    fwrite(\STDERR, $error::class."\n");
    exit(1);
}
