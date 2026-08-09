<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * DoctrineUserRepository не строится через контейнер напрямую: пока
 * никакой Application-класс её не потребляет (первый — PR3), компилятор
 * контейнера удаляет неиспользуемый private-сервис. EntityManagerInterface
 * используется повсеместно и всегда доступен — этого достаточно, чтобы
 * собрать реальный репозиторий поверх настоящего Postgres (ADR-005).
 */
final class DoctrineUserRepositoryTest extends KernelTestCase
{
    public function testFindByEmailIsCaseInsensitive(): void
    {
        self::bootKernel();
        $users = $this->users();

        UserBuilder::aUser()->withEmail('Owner@Example.com')->persistWith($users);

        $found = $users->findByEmail('owner@example.com');

        self::assertNotNull($found);
        self::assertSame('owner@example.com', $found->email());
    }

    public function testFindByEmailReturnsNullWhenNotFound(): void
    {
        self::bootKernel();
        $users = $this->users();

        self::assertNull($users->findByEmail('nobody@example.com'));
    }

    public function testDuplicateEmailIsRejectedByUniqueIndex(): void
    {
        self::bootKernel();
        $users = $this->users();
        $entityManager = $this->entityManager();

        UserBuilder::aUser()->withEmail('dup@example.com')->persistWith($users);

        $this->expectException(UniqueConstraintViolationException::class);

        // Уникальный индекс на email реален на уровне БД, не только
        // в Doctrine-мэппинге (стиль ADR-005, как в MarketplaceAccount).
        $entityManager->getConnection()->insert('"user"', [
            'id' => (string) Uuid::v7(),
            'email' => 'dup@example.com',
            'password_hash' => 'stub',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function users(): DoctrineUserRepository
    {
        return new DoctrineUserRepository($this->entityManager());
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
