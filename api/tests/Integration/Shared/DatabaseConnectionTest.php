<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Доказывает, что интеграционный уровень целиком работает: контейнер,
 * реальное соединение с Postgres, запись и чтение, откат dama/doctrine-test-bundle
 * между тестами. Таблица создаётся и заполняется в теле теста — без миграций
 * и сущностей, которых на этом шаге ещё нет.
 */
final class DatabaseConnectionTest extends KernelTestCase
{
    public function testWriteAndReadThroughRealConnection(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        $connection->executeStatement(
            'CREATE TABLE integration_probe (id INT PRIMARY KEY, label VARCHAR(50) NOT NULL)',
        );
        $connection->insert('integration_probe', ['id' => 1, 'label' => 'stage-2-step-1']);

        $row = $connection->fetchAssociative('SELECT label FROM integration_probe WHERE id = 1');

        self::assertSame('stage-2-step-1', $row['label'] ?? null);
    }
}
