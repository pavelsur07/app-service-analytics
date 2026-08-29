<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Domain\ValueObject\AdminRole;
use App\Identity\Infrastructure\Repository\DoctrineAdministratorRepository;
use App\Tests\Support\Builder\AdministratorBuilder;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * «У каждого администратора известен автор» — инвариант, на котором
 * держится признак ADR-011: append-only сущности не нужен журнал ровно
 * потому, что каждый переход хранит, кто его выполнил.
 *
 * Проверяется, что его держит база, а не докблок. Все три ограничения
 * обходятся одной строчкой кода, если их нет, и разницы снаружи
 * не видно, пока не понадобится ответить, кто завёл эту учётку.
 */
final class AdministratorInvariantsTest extends KernelTestCase
{
    public function testAdministratorWithoutAuthorIsRejectedUnlessSuperAdmin(): void
    {
        self::bootKernel();

        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);

        // Нижняя роль без автора: Admin заводится действием SuperAdmin,
        // и актор у него есть всегда (ADR-017).
        $this->insertRaw(role: AdminRole::Admin->value, createdBy: null);
    }

    public function testNonExistentAuthorIsRejected(): void
    {
        self::bootKernel();

        $this->expectException(ForeignKeyConstraintViolationException::class);

        // Произвольный uuid — «в колонке что-то есть», но след ведёт
        // в никуда. Без внешнего ключа такая строка вставилась бы.
        $this->insertRaw(role: AdminRole::Admin->value, createdBy: (string) Uuid::v7());
    }

    public function testOnlyOneAdministratorMayExistWithoutAuthor(): void
    {
        self::bootKernel();
        $administrators = $this->administrators();

        AdministratorBuilder::aBootstrapSuperAdmin()->withEmail('first@conwix.local')->persistWith($administrators);

        $this->expectException(UniqueConstraintViolationException::class);

        // Вторая привилегированная учётка без актора: «администраторов
        // ещё не существует» — оправдание, верное только для первой.
        $this->insertRaw(role: AdminRole::SuperAdmin->value, createdBy: null, email: 'second@conwix.local');
    }

    public function testAdministratorCreatedByAnotherAdministratorIsAccepted(): void
    {
        self::bootKernel();
        $administrators = $this->administrators();

        $boss = AdministratorBuilder::aBootstrapSuperAdmin()->withEmail('boss@conwix.local')->persistWith($administrators);
        $admin = AdministratorBuilder::anAdministrator()->withEmail('ops@conwix.local')->createdBy($boss)->persistWith($administrators);

        self::assertNotNull($admin->createdByAdminId());
        self::assertSame((string) $boss->id(), (string) $admin->createdByAdminId());
    }

    private function insertRaw(string $role, ?string $createdBy, string $email = 'raw@conwix.local'): void
    {
        $this->entityManager()->getConnection()->insert('administrator', [
            'id' => (string) Uuid::v7(),
            'email' => $email,
            'password_hash' => 'stub-hash',
            'role' => $role,
            'created_by_admin_id' => $createdBy,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function administrators(): DoctrineAdministratorRepository
    {
        return new DoctrineAdministratorRepository($this->entityManager());
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
