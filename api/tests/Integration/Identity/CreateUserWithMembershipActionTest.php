<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\CreateUserWithMembershipAction;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\ValueObject\CompanyMemberRole;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Tests\Support\Builder\CompanyBuilder;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Ручной онбординг (ADR-007): создаёт User + CompanyMember в одной
 * команде. Повторный вызов с тем же email — конфликт перехвачен на
 * вставке (CLAUDE.md §4), не "найти и вставить".
 */
final class CreateUserWithMembershipActionTest extends KernelTestCase
{
    public function testCreatesUserAndMembership(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var CreateUserWithMembershipAction $action */
        $action = $container->get(CreateUserWithMembershipAction::class);
        /** @var CompanyRepository $companies */
        $companies = $container->get(CompanyRepository::class);

        $company = CompanyBuilder::aCompany()->persistWith($companies);

        $user = ($action)('owner@example.com', 'stub-hash', $company->id(), CompanyMemberRole::Owner);

        self::assertSame('owner@example.com', $user->email());
        self::assertSame('stub-hash', $user->passwordHash());

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $companyMembers = new DoctrineCompanyMemberRepository($entityManager);
        self::assertTrue($companyMembers->existsForUserAndCompany($company->id()->toRfc4122(), $user->id()->toRfc4122()));
    }

    public function testRepeatingTheSameCallIsRejectedByUniqueIndexOnEmail(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var CreateUserWithMembershipAction $action */
        $action = $container->get(CreateUserWithMembershipAction::class);
        /** @var CompanyRepository $companies */
        $companies = $container->get(CompanyRepository::class);

        $company = CompanyBuilder::aCompany()->persistWith($companies);
        ($action)('dup@example.com', 'stub-hash', $company->id(), CompanyMemberRole::Owner);

        $this->expectException(UniqueConstraintViolationException::class);

        // Тот же email второй раз — консольная команда перехватывает это
        // исключение и сообщает "уже существует" (не падает трассировкой);
        // здесь важен сам факт, что перехватывать есть что: уникальный
        // индекс на email реален на уровне БД.
        ($action)('dup@example.com', 'another-hash', $company->id(), CompanyMemberRole::Owner);
    }
}
