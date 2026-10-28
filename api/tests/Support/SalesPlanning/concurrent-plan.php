<?php

declare(strict_types=1);

use App\Kernel;
use App\SalesPlanning\Application\ApproveSalesPlanAction;
use App\SalesPlanning\Application\CancelSalesPlanAction;
use App\SalesPlanning\Application\ChangeSalesPlanDayAction;
use App\SalesPlanning\Application\CreateSalesPlanRevisionAction;
use App\SalesPlanning\Domain\SalesPlanMetric;
use App\SalesPlanning\Domain\SalesPlanRepository;
use App\Tests\Support\Builder\SalesPlanBuilder;
use App\Tests\Support\Builder\SalesPlanLineBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 3).'/vendor/autoload.php';

/** @param array<string, mixed> $event */
function emitPlanEvent(array $event): void
{
    fwrite(\STDOUT, json_encode($event, \JSON_THROW_ON_ERROR)."\n");
    fflush(\STDOUT);
}

try {
    if ('test' !== getenv('APP_ENV')) {
        throw new RuntimeException('Only APP_ENV=test is allowed.');
    }
    $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
    (new Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');
    $line = fgets(\STDIN);
    $input = false === $line ? null : json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
    if (!is_array($input) || !is_string($input['database'] ?? null) || !is_string($input['companyId'] ?? null)
        || !Uuid::isValid($input['companyId']) || !is_string($input['operation'] ?? null)) {
        throw new RuntimeException('Invalid synthetic worker input.');
    }
    $kernel = new Kernel('test', true);
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    if (!$container instanceof ContainerInterface) {
        throw new RuntimeException('Missing test container.');
    }
    $em = $container->get(EntityManagerInterface::class);
    if (!$em instanceof EntityManagerInterface) {
        throw new RuntimeException('Missing test entity manager.');
    }
    $db = $em->getConnection();
    $database = $db->fetchOne('SELECT current_database()');
    if ('test' !== $kernel->getEnvironment() || !is_string($database) || 1 !== preg_match('/^app_test(?:[1-9][0-9]*)?$/D', $database)
        || $database !== $input['database']) {
        throw new RuntimeException('Worker refuses a nonmatching test worker database.');
    }
    $companyId = $input['companyId'];
    $name = 'A10 concurrency '.$companyId;
    // Любая операция/cleanup отказывает при существующей настоящей компании.
    if (false !== $db->fetchOne('SELECT id FROM company WHERE id = :id', ['id' => $companyId])
        || 0 !== $db->fetchOne('SELECT COUNT(*) FROM sales_plan WHERE company_id = :id AND name <> :name', ['id' => $companyId, 'name' => $name])) {
        throw new RuntimeException('Worker refuses nonsynthetic company data.');
    }
    $db->executeStatement("SET lock_timeout = '10s'");
    $db->executeStatement("SET statement_timeout = '15s'");
    $pid = $db->fetchOne('SELECT pg_backend_pid()');
    $plans = $container->get(SalesPlanRepository::class);
    if (!$plans instanceof SalesPlanRepository) {
        throw new RuntimeException('Missing test repository.');
    }
    $actor = Uuid::v7()->toRfc4122();
    if ('cleanup' === $input['operation']) {
        $db->transactional(static function () use ($db, $companyId): void {
            foreach (['audit_record', 'sales_plan_day_claim', 'sales_plan_line', 'sales_plan'] as $table) {
                $db->executeStatement('DELETE FROM '.$table.' WHERE company_id = :id', ['id' => $companyId]);
            }
        });
        emitPlanEvent(['event' => 'result', 'cleaned' => true]);
    } elseif ('seed' === $input['operation']) {
        $account = Uuid::v7();
        $created = [];
        for ($i = 0; $i < 2; ++$i) {
            $plan = SalesPlanBuilder::aSalesPlan()->withName($name)->withCompanyId(Uuid::fromString($companyId))
                ->withMarketplaceAccountId($account)->withMetric(SalesPlanMetric::RetainedSales)
                ->withPeriod(new DateTimeImmutable('2026-07-01'), new DateTimeImmutable('2026-07-03'))->build();
            $plans->add($plan, SalesPlanLineBuilder::linesOf($plan)->withMarketplaceSku('101')->withTotalQuantity(6)->build());
            $created[] = ['id' => $plan->id()->toRfc4122(), 'version' => $plan->version()];
        }
        emitPlanEvent(['event' => 'result', 'plans' => $created, 'pid' => $pid]);
    } else {
        $planId = $input['planId'] ?? null;
        $version = $input['version'] ?? null;
        if (!is_string($planId) || !Uuid::isValid($planId) || !is_int($version) || $version < 1) {
            throw new RuntimeException('Invalid synthetic command.');
        }
        $held = true === ($input['hold'] ?? false);
        if ($held) {
            $db->beginTransaction();
        }
        emitPlanEvent(['event' => 'started', 'pid' => $pid]);
        $operation = $input['operation'];
        $action = $container->get(match ($operation) {
            'approve' => ApproveSalesPlanAction::class,
            'edit' => ChangeSalesPlanDayAction::class,
            'copy' => CreateSalesPlanRevisionAction::class,
            'cancel' => CancelSalesPlanAction::class,
            default => throw new RuntimeException('Unknown test command.'),
        });
        if ($action instanceof ChangeSalesPlanDayAction) {
            $result = $action($companyId, $planId, $version, '101', new DateTimeImmutable('2026-07-02'), 9, 22222, $actor);
        } elseif ($action instanceof ApproveSalesPlanAction || $action instanceof CancelSalesPlanAction || $action instanceof CreateSalesPlanRevisionAction) {
            $result = $action($companyId, $planId, $version, $actor);
        } else {
            throw new RuntimeException('Unexpected test action.');
        }
        $outcome = $result->outcome;
        $code = $outcome instanceof BackedEnum ? $outcome->value : match ($outcome) {
            App\SalesPlanning\Application\EditSalesPlanOutcome::Saved => 'saved',
            App\SalesPlanning\Application\EditSalesPlanOutcome::VersionConflict => 'version_conflict',
            default => $outcome->name,
        };
        $event = ['event' => $held ? 'held' : 'result', 'outcome' => $code, 'pid' => $pid];
        if ($result instanceof App\SalesPlanning\Application\CreateSalesPlanRevisionResult && null !== $result->draft) {
            $event['draftId'] = $result->draft->id()->toRfc4122();
        }
        emitPlanEvent($event);
        if ($held) {
            stream_set_timeout(\STDIN, 10);
            if ("commit\n" !== fgets(\STDIN)) {
                throw new RuntimeException('Commit barrier deadline exceeded.');
            }
            $db->commit();
            emitPlanEvent(['event' => 'committed', 'pid' => $pid]);
        }
    }
    $kernel->shutdown();
} catch (Throwable $failure) {
    // Не выводить DSN/stack/environment. Exit code + тип достаточны для bounded diagnostics.
    fwrite(\STDERR, 'Synthetic lifecycle worker failed: '.get_debug_type($failure)."\n");
    exit(1);
}
