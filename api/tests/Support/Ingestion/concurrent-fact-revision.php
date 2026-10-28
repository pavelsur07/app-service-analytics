<?php

declare(strict_types=1);

use App\Ingestion\Domain\FactCaptureOrigin;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceReturnFactWriter;
use App\Ingestion\Infrastructure\Persistence\DoctrineSalesFactWriter;
use App\Kernel;
use App\Tests\Support\Builder\MarketplaceReturnFactBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 3).'/vendor/autoload.php';

/** @param array<string, int|string|bool> $event */
function emitRevisionEvent(array $event): void
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
    $input = json_decode((string) fgets(\STDIN), true, flags: \JSON_THROW_ON_ERROR);
    if (!is_array($input) || !is_string($input['database'] ?? null) || !is_string($input['companyId'] ?? null)
        || !Uuid::isValid($input['companyId']) || !is_string($input['accountId'] ?? null)
        || !Uuid::isValid($input['accountId']) || !is_string($input['rawId'] ?? null)
        || !Uuid::isValid($input['rawId']) || !is_string($input['kind'] ?? null)
        || !in_array($input['kind'], ['sales', 'returns'], true) || !is_int($input['quantity'] ?? null)) {
        throw new RuntimeException('Invalid synthetic worker input.');
    }
    $kernel = new Kernel('test', true);
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    if (!$container instanceof ContainerInterface) {
        throw new RuntimeException('Missing test container.');
    }
    $db = $container->get(Connection::class);
    if (!$db instanceof Connection) {
        throw new RuntimeException('Missing DBAL connection.');
    }
    $database = $db->fetchOne('SELECT current_database()');
    if (!is_string($database) || 1 !== preg_match('/^app_test(?:[1-9][0-9]*)?$/D', $database)
        || $database !== $input['database']) {
        throw new RuntimeException('Worker refuses a nonmatching test worker database.');
    }
    $company = $input['companyId'];
    if (false !== $db->fetchOne('SELECT id FROM company WHERE id = ?', [$company])) {
        throw new RuntimeException('Worker refuses an existing company.');
    }
    if ('cleanup' === ($input['operation'] ?? null)) {
        $db->transactional(static function (Connection $db) use ($company): void {
            foreach (['sales_fact_revision', 'marketplace_return_fact_revision', 'sales_fact', 'marketplace_return_fact'] as $table) {
                $db->executeStatement('DELETE FROM '.$table.' WHERE company_id = ?', [$company]);
            }
        });
        emitRevisionEvent(['event' => 'cleaned']);
        exit(0);
    }
    $db->executeStatement("SET lock_timeout = '10s'");
    $db->executeStatement("SET statement_timeout = '15s'");
    $pid = $db->fetchOne('SELECT pg_backend_pid()');
    if (!is_int($pid)) {
        throw new RuntimeException('Invalid backend PID.');
    }
    emitRevisionEvent(['event' => 'ready', 'pid' => $pid]);
    if ("go\n" !== fgets(\STDIN)) {
        throw new RuntimeException('Missing write barrier.');
    }
    $companyId = Uuid::fromString($company);
    $accountId = Uuid::fromString($input['accountId']);
    $rawId = Uuid::fromString($input['rawId']);
    $clock = new MockClock('2026-10-28 12:00:00 UTC');
    $db->beginTransaction();
    if ('sales' === $input['kind']) {
        $fact = SalesFactBuilder::aSalesFact()->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withSourceRowId('B02-RACE|SKU')->withRawDocumentId($rawId)->withQuantity($input['quantity'])->build();
        (new DoctrineSalesFactWriter($db, $clock))->upsertAll([$fact], FactCaptureOrigin::LivePolling);
    } else {
        $fact = MarketplaceReturnFactBuilder::aMarketplaceReturnFact()->withCompanyId($companyId)->withMarketplaceAccountId($accountId)
            ->withSourceRowId('B02-RACE')->withRawDocumentId($rawId)->withQuantity($input['quantity'])->build();
        (new DoctrineMarketplaceReturnFactWriter($db, $clock))->upsertAll([$fact], FactCaptureOrigin::LivePolling);
    }
    emitRevisionEvent(['event' => 'written']);
    $finish = fgets(\STDIN);
    if ("commit\n" === $finish) {
        $db->commit();
    } elseif ("rollback\n" === $finish) {
        $db->rollBack();
    } else {
        throw new RuntimeException('Missing commit barrier.');
    }
    emitRevisionEvent(['event' => 'finished']);
} catch (Throwable $failure) {
    fwrite(\STDERR, $failure::class.': '.$failure->getMessage());
    exit(1);
}
