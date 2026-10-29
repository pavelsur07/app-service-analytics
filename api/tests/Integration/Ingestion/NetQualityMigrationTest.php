<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\Migrations\Version\DbalMigrationFactory;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NetQualityMigrationTest extends KernelTestCase
{
    public function testLocalRoundtripAndGuardKeepQualityEvidenceIntact(): void
    {
        self::bootKernel(['debug' => false]);
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $params = $connection->getParams();
        unset($params['wrapperClass']);
        $db = DriverManager::getConnection($params);
        $database = $db->fetchOne('SELECT current_database()');
        self::assertIsString($database);
        self::assertMatchesRegularExpression('/^app_test(?:[1-9][0-9]*)?$/D', $database);
        try {
            // A connection-local shadow exercises the actual migration; public tables stay intact.
            $db->executeStatement('CREATE TEMP TABLE net_forecast_artifact (kind VARCHAR(5) NOT NULL)');
            $db->executeStatement("INSERT INTO net_forecast_artifact (kind) VALUES ('input')");
            require_once __DIR__.'/../../../migrations/Version20261029130000.php';
            $factory = new DbalMigrationFactory($db, new NullLogger());
            foreach (['up', 'down', 'up'] as $direction) {
                $migration = $factory->createVersion('DoctrineMigrations\\Version20261029130000');
                if ('up' === $direction) {
                    $migration->up(new Schema());
                } else {
                    $migration->down(new Schema());
                }
                foreach ($migration->getSql() as $query) {
                    $db->executeStatement($query->getStatement());
                }
            }
            $db->executeStatement("INSERT INTO net_forecast_artifact (kind) VALUES ('quality-protocol')");
            try {
                $migration = $factory->createVersion('DoctrineMigrations\\Version20261029130000');
                $migration->down(new Schema());
                self::fail('Down must refuse narrowing while evidence exists.');
            } catch (AbortMigration) {
                self::assertSame([], $migration->getSql());
            }
            self::assertSame(['input', 'quality-protocol'], $db->fetchFirstColumn('SELECT kind FROM net_forecast_artifact ORDER BY kind'));
        } finally {
            $db->close();
        }
    }
}
