<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Version\DbalMigrationFactory;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class FactRevisionMigrationTest extends KernelTestCase
{
    public function testLegacyZeroAndNewWritesAreProtectedBeforeSeparateValidation(): void
    {
        self::bootKernel();
        $testConnection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $testConnection);
        // A separate DBAL connection commits the fixture migration before VALIDATE.
        // The regular DAMA test connection and public tables remain untouched.
        $db = DriverManager::getConnection($testConnection->getParams());
        $database = $db->fetchOne('SELECT current_database()');
        self::assertIsString($database);
        self::assertMatchesRegularExpression('/^app_test(?:[1-9][0-9]*)?$/D', $database);
        $schemaName = 'b02_revision_'.str_replace('-', '', Uuid::v7()->toRfc4122());
        $schema = $db->quoteSingleIdentifier($schemaName);
        $created = false;
        try {
            $db->beginTransaction();
            $db->executeStatement('CREATE SCHEMA '.$schema);
            $created = true;
            $db->executeStatement('SET LOCAL search_path TO '.$schema.', public');
            foreach (['sales_fact', 'marketplace_return_fact'] as $table) {
                $db->executeStatement('CREATE TABLE '.$schema.'.'.$table." (id INT PRIMARY KEY, marker TEXT DEFAULT 'legacy' NOT NULL)");
                $db->executeStatement('INSERT INTO '.$schema.'.'.$table.' (id) VALUES (1)');
            }
            require_once __DIR__.'/../../../migrations/Version20261028120000.php';
            $migration = (new DbalMigrationFactory($db, new NullLogger()))->createVersion('DoctrineMigrations\\Version20261028120000');
            self::assertInstanceOf(AbstractMigration::class, $migration);
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $db->executeStatement($query->getStatement());
            }
            $db->commit();
            self::assertSame(0, $db->getTransactionNestingLevel());

            foreach (['sales_fact', 'marketplace_return_fact'] as $table) {
                $constraint = 'chk_'.$table.'_revision';
                self::assertSame([['marker' => 'legacy', 'fact_revision' => 0]], $db->fetchAllAssociative('SELECT marker, fact_revision FROM '.$schema.'.'.$table.' ORDER BY id'));
                self::assertFalse($this->isValidated($db, $schemaName, $constraint));
                foreach ([
                    'INSERT INTO '.$schema.'.'.$table.' (id, fact_revision) VALUES (2, -1)',
                    'UPDATE '.$schema.'.'.$table.' SET fact_revision = -1 WHERE id = 1',
                ] as $negativeWrite) {
                    try {
                        $db->executeStatement($negativeWrite);
                        self::fail('NOT VALID must enforce the CHECK for every new write.');
                    } catch (DriverException $failure) {
                        self::assertSame('23514', $failure->getSQLState());
                    }
                }
                // One autocommit statement per table, after migration COMMIT.
                self::assertSame(0, $db->getTransactionNestingLevel());
                $db->executeStatement('ALTER TABLE '.$schema.'.'.$table.' VALIDATE CONSTRAINT '.$constraint);
                self::assertTrue($this->isValidated($db, $schemaName, $constraint));
                self::assertSame(0, $db->getTransactionNestingLevel());
                self::assertSame(0, $db->fetchOne('SELECT fact_revision FROM '.$schema.'.'.$table.' WHERE id = 1'));
            }
        } finally {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }
            if ($created) {
                $db->executeStatement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');
            }
            $db->close();
        }
    }

    private function isValidated(Connection $db, string $schemaName, string $constraint): bool
    {
        $validated = $db->fetchOne('SELECT c.convalidated FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace WHERE n.nspname = ? AND c.conname = ?', [$schemaName, $constraint]);
        self::assertIsBool($validated);

        return $validated;
    }
}
