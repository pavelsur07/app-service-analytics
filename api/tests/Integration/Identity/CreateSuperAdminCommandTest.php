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
 * отработала», а свойства этого пути: роль не выбирается, пароль
 * не приходит аргументом, повторный запуск не создаёт дубль.
 *
 * Последнее — не мелочь: команда доступна тому, у кого есть доступ
 * к боевому серверу, и «повторный запуск переписывает пароль»
 * превратило бы опечатку в тихую смену учётных данных.
 */
final class CreateSuperAdminCommandTest extends KernelTestCase
{
    public function testCreatesSuperAdminWithHashedPassword(): void
    {
        self::bootKernel();
        $administrators = $this->administrators();

        $exitCode = $this->runCommand('boss@conwix.local', 'boss-password');

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

    public function testPasswordIsNotAnArgument(): void
    {
        self::bootKernel();

        // Аргумент осел бы в истории оболочки и в списке процессов.
        // Проверяется определение команды, а не поведение: именно оно
        // и есть предмет — вернуть аргумент обратно легко и незаметно.
        $definition = $this->command()->getDefinition();

        self::assertFalse($definition->hasArgument('password'));
        self::assertSame(['email'], array_keys($definition->getArguments()));
    }

    public function testRoleCannotBeChosen(): void
    {
        self::bootKernel();

        // Опция --role была бы вторым путём создания Admin — мимо формы,
        // мимо актора и мимо аудит-журнала (ADR-017).
        self::assertFalse($this->command()->getDefinition()->hasOption('role'));
    }

    public function testEmptyPasswordIsRejectedAndNothingIsCreated(): void
    {
        self::bootKernel();
        $administrators = $this->administrators();

        $exitCode = $this->runCommand('empty@conwix.local', '   ');

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertNull($administrators->findByEmail('empty@conwix.local'));
    }

    public function testRepeatedRunDoesNotDuplicateOrChangeCredentials(): void
    {
        self::bootKernel();
        $administrators = $this->administrators();

        $this->runCommand('twice@conwix.local', 'first-password');
        $first = $administrators->findByEmail('twice@conwix.local');
        self::assertNotNull($first);

        $exitCode = $this->runCommand('twice@conwix.local', 'second-password');

        self::assertSame(Command::FAILURE, $exitCode);
        $again = $administrators->findByEmail('twice@conwix.local');
        self::assertNotNull($again);
        self::assertSame((string) $first->id(), (string) $again->id());
        self::assertSame($first->passwordHash(), $again->passwordHash(), 'повторный запуск не должен менять пароль');
    }

    public function testSecondBootstrapWithAnotherEmailIsRefusedByTheDatabase(): void
    {
        self::bootKernel();
        $administrators = $this->administrators();

        self::assertSame(Command::SUCCESS, $this->runCommand('first@conwix.local', 'first-password'));

        // Другой email — уникальность email не мешает. Отвергает
        // частичный уникальный индекс на строках без автора: вторая
        // привилегированная учётка без актора нарушила бы признак
        // ADR-011, по которому этой сущности не нужен журнал.
        $exitCode = $this->runCommand('second@conwix.local', 'second-password');

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertNull($administrators->findByEmail('second@conwix.local'));
        self::assertNotNull($administrators->findByEmail('first@conwix.local'));
    }

    private function runCommand(string $email, string $password): int
    {
        $tester = new CommandTester($this->command());
        $tester->setInputs([$password]);

        return $tester->execute(['email' => $email]);
    }

    private function command(): Command
    {
        /** @var \Symfony\Component\HttpKernel\KernelInterface $kernel */
        $kernel = self::$kernel;

        return (new Application($kernel))->find('app:identity:create-super-admin');
    }

    private function administrators(): DoctrineAdministratorRepository
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return new DoctrineAdministratorRepository($entityManager);
    }
}
