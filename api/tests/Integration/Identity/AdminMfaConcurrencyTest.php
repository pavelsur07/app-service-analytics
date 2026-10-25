<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Infrastructure\Crypto\AdminMfaSecretCipher;
use App\Identity\Infrastructure\Repository\DoctrineAdministratorRepository;
use App\Identity\Infrastructure\Repository\DoctrineAdminMfaRepository;
use App\Identity\Infrastructure\Security\OtpAdminTotp;
use App\Tests\Support\Builder\AdministratorBuilder;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class AdminMfaConcurrencyTest extends KernelTestCase
{
    public function testTwoProcessesCannotConsumeTheSameRecoveryCode(): void
    {
        self::bootKernel();
        $shared = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $shared);
        $params = $shared->getConnection()->getParams();
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => $params['host'], 'port' => $params['port'],
            'user' => $params['user'], 'password' => $params['password'], 'dbname' => $params['dbname'],
            'serverVersion' => $params['serverVersion'],
        ]);
        // Отдельный EM/connection вне DAMA: второй процесс обязан видеть
        // зафиксированную fixture. В finally удаляются только её строки.
        $em = new EntityManager($connection, $shared->getConfiguration());
        $key = base64_decode('KS8/YKyyhc1xV0WvkS0MPDt3IjvqDQ3stBZZYu+OXZk=', true);
        self::assertIsString($key);
        $repo = new DoctrineAdminMfaRepository($em, new OtpAdminTotp(), new AdminMfaSecretCipher($key));
        $admin = AdministratorBuilder::aBootstrapSuperAdmin()->withEmail('race-'.Uuid::v7()->toRfc4122().'@example.test')->persistWith(new DoctrineAdministratorRepository($em));
        $process = null;
        try {
            $now = new \DateTimeImmutable('@1792929600');
            $enrollment = $repo->beginEnrollment($admin->id(), 0, $now);
            $enabled = $repo->confirmEnrollment($admin->id(), 1, TOTP::createFromSecret($enrollment->secret)->at(1792929600), $now);
            self::assertNotNull($enabled);
            $code = $enabled->recoveryCodes[0];
            $connection->beginTransaction();
            self::assertNotNull($repo->verify($admin->id(), 1, $code, $now));
            $applicationName = 'mfa-race-'.$admin->id()->toRfc4122();
            $process = proc_open(['timeout', '15', \PHP_BINARY, \dirname(__DIR__, 2).'/Support/Process/verify-admin-mfa.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            fwrite($pipes[0], json_encode([
                'administratorId' => $admin->id()->toRfc4122(), 'version' => 1, 'code' => $code,
                'database' => $connection->getDatabase(), 'applicationName' => $applicationName,
            ], \JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $deadline = hrtime(true) + 10_000_000_000;
            $blocked = false;
            do {
                // PostgreSQL кэширует снимок статистики до конца транзакции.
                $connection->executeStatement('SELECT pg_stat_clear_snapshot()');
                $blocked = 'Lock' === $connection->fetchOne('SELECT wait_event_type FROM pg_stat_activity WHERE application_name = :name', ['name' => $applicationName]);
                $status = proc_get_status($process);
                if (!$status['running']) {
                    self::fail('Concurrent verifier exited before waiting: '.stream_get_contents($pipes[2]));
                }
            } while (!$blocked && hrtime(true) < $deadline);
            self::assertTrue($blocked, 'Expected another backend to wait; last activity: '.json_encode($connection->fetchAllAssociative('SELECT application_name, wait_event_type, state FROM pg_stat_activity WHERE application_name = :name', ['name' => $applicationName]), \JSON_THROW_ON_ERROR));
            $connection->commit();
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            $process = null;
            self::assertSame(0, $exit, false === $error ? '' : $error);
            self::assertSame('{"verified":false}', $output);
            self::assertNull($repo->verify($admin->id(), 1, $code, $now));
        } finally {
            if (\is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $id = ['id' => $admin->id()->toRfc4122()];
            $connection->executeStatement('DELETE FROM administrator_mfa_recovery_code WHERE administrator_id = :id', $id);
            $connection->executeStatement('DELETE FROM administrator_mfa WHERE administrator_id = :id', $id);
            $connection->executeStatement('DELETE FROM audit_record WHERE actor_admin_id = :id', $id);
            $connection->executeStatement('DELETE FROM administrator WHERE id = :id', $id);
            $connection->close();
        }
    }
}
