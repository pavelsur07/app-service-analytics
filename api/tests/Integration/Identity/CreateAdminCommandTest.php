<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Domain\ValueObject\AdminRole;
use App\Identity\Infrastructure\Repository\DoctrineAdministratorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Единственный путь к роли super_admin (ADR-017). Проверяется не «команда
 * отработала», а свойства этого пути: роль задаётся явно и разбирается
 * строго, пароль ложится хэшем, повторный запуск не создаёт дубль
 * и не переписывает роль.
 *
 * Последнее — не мелочь: команда доступна тому, у кого есть доступ
 * к боевому серверу, и «повторный запуск повышает роль» превратило бы
 * опечатку в тихое повышение прав.
 */
final class CreateAdminCommandTest extends KernelTestCase
{
    public function testCreatesSuperAdminWithHashedPassword(): void
    {
        self::bootKernel();
        $administrators = $this->administrators();

        $exitCode = $this->runCommand(['email' => 'boss@conwix.local', 'password' => 'boss-password', '--role' => 'super_admin']);

        self::assertSame(Command::SUCCESS, $exitCode);

        $administrator = $administrators->findByEmail('boss@conwix.local');
        self::assertNotNull($administrator);
        self::assertSame(AdminRole::SuperAdmin, $administrator->role());
        self::assertNull($administrator->createdByAdminId(), 'у bootstrap-администратора автора нет');

        self::assertNotSame('boss-password', $administrator->passwordHash(), 'пароль не должен лежать в открытом виде');
        $hasher = self::getContainer()->get(PasswordHasherFactoryInterface::class);
        self::assertInstanceOf(PasswordHasherFactoryInterface::class, $hasher);
        self::assertTrue($hasher->getPasswordHasher($administrator)->verify($administrator->passwordHash(), 'boss-password'));
    }

    public function testDefaultsToLowerRole(): void
    {
        self::bootKernel();
        $administrators = $this->administrators();

        $this->runCommand(['email' => 'ops@conwix.local', 'password' => 'ops-password']);

        $administrator = $administrators->findByEmail('ops@conwix.local');
        self::assertNotNull($administrator);
        // Умолчание — нижняя роль: забытый --role не должен раздавать
        // права заведения администраторов.
        self::assertSame(AdminRole::Admin, $administrator->role());
    }

    public function testUnknownRoleIsRejectedAndNothingIsCreated(): void
    {
        self::bootKernel();
        $administrators = $this->administrators();

        $exitCode = $this->runCommand(['email' => 'typo@conwix.local', 'password' => 'x', '--role' => 'superadmin']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertNull($administrators->findByEmail('typo@conwix.local'));
    }

    public function testRepeatedRunDoesNotDuplicateOrEscalateRole(): void
    {
        self::bootKernel();
        $administrators = $this->administrators();

        $this->runCommand(['email' => 'twice@conwix.local', 'password' => 'first-password']);
        $first = $administrators->findByEmail('twice@conwix.local');
        self::assertNotNull($first);

        // Тот же email, но верхняя роль: конфликт перехватывается
        // на вставке (CLAUDE.md §4), повышения не происходит.
        $exitCode = $this->runCommand(['email' => 'twice@conwix.local', 'password' => 'second-password', '--role' => 'super_admin']);

        self::assertSame(Command::SUCCESS, $exitCode);
        $again = $administrators->findByEmail('twice@conwix.local');
        self::assertNotNull($again);
        self::assertSame((string) $first->id(), (string) $again->id());
        self::assertSame(AdminRole::Admin, $again->role(), 'повторный запуск не должен повышать роль');
    }

    /**
     * @param array<string, string|bool> $input
     */
    private function runCommand(array $input): int
    {
        /** @var \Symfony\Component\HttpKernel\KernelInterface $kernel */
        $kernel = self::$kernel;
        $tester = new CommandTester((new Application($kernel))->find('app:identity:create-admin'));

        return $tester->execute($input);
    }

    private function administrators(): DoctrineAdministratorRepository
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return new DoctrineAdministratorRepository($entityManager);
    }
}
