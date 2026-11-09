<?php

declare(strict_types=1);

use App\Ingestion\Domain\OzonCampaignCatalogData;
use App\Ingestion\Domain\OzonCampaignCatalogItem;
use App\Ingestion\Infrastructure\Persistence\DoctrineOzonCampaignCatalogRepository;
use App\Kernel;
use App\Tests\Support\Builder\OzonCampaignCatalogSnapshotBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 3).'/vendor/autoload.php';

try {
    if ('test' !== getenv('APP_ENV')) {
        throw new RuntimeException('Test environment required.');
    }
    $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'test';
    (new Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');
    $input = json_decode((string) stream_get_contents(\STDIN), true, flags: \JSON_THROW_ON_ERROR);
    if (!is_array($input) || !is_string($input['company'] ?? null) || !Uuid::isValid($input['company']) || !is_string($input['account'] ?? null) || !Uuid::isValid($input['account']) || !is_string($input['database'] ?? null)) {
        throw new RuntimeException('Invalid synthetic input.');
    }
    $kernel = new Kernel('test', true);
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    if (!$container instanceof ContainerInterface) {
        throw new RuntimeException('Missing test container.');
    }
    $application = $container->get(Connection::class);
    if (!$application instanceof Connection) {
        throw new RuntimeException('Missing test connection.');
    }
    $db = DriverManager::getConnection($application->getParams());
    $database = $db->fetchOne('SELECT current_database()');
    if (!is_string($database) || 1 !== preg_match('/\Aapp_test(?:[1-9][0-9]*)?\z/', $database) || $database !== $input['database'] || false !== $db->fetchOne('SELECT id FROM company WHERE id=?', [$input['company']])) {
        throw new RuntimeException('Unsafe test database or scope.');
    }
    $db->executeStatement("SET statement_timeout='10s'");
    $pid = $db->fetchOne('SELECT pg_backend_pid()');
    if (!is_int($pid)) {
        throw new RuntimeException('Invalid backend PID.');
    }
    fwrite(\STDOUT, json_encode(['event' => 'ready', 'pid' => $pid], \JSON_THROW_ON_ERROR)."\n");
    fflush(\STDOUT);
    OzonCampaignCatalogSnapshotBuilder::aSnapshot()->withCompany(Uuid::fromString($input['company']))->withAccount(Uuid::fromString($input['account']))
        ->withReceivedAt(new DateTimeImmutable('2026-11-09T10:00:00Z'))
        ->withData(new OzonCampaignCatalogData([new OzonCampaignCatalogItem('1', 'Old', 'CAMPAIGN_STATE_RUNNING', 'SKU', 'CPC')], 1, true))
        ->persistWith(new DoctrineOzonCampaignCatalogRepository($db));
    $db->close();
    fwrite(\STDOUT, "finished\n");
} catch (Throwable) {
    fwrite(\STDERR, "catalog synthetic worker failed\n");
    exit(1);
}
