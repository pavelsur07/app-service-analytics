<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DoctrineUserRepository/DoctrineCompanyMemberRepository не строятся через
 * контейнер напрямую: пока никакой Application-класс их не потребляет
 * (первый — PR3), компилятор контейнера удаляет неиспользуемый
 * private-сервис. CompanyRepository — уже потребляется
 * RegisterCompanyWithOzonAccountAction, поэтому доступен как обычно.
 */
final class DoctrineCompanyMemberRepositoryTest extends KernelTestCase
{
    public function testExistsForUserAndCompanyIsTrueOnlyForTheActualPair(): void
    {
        self::bootKernel();
        [$companies, $users, $companyMembers] = $this->repositories();

        $companyA = CompanyBuilder::aCompany()->persistWith($companies);
        $companyB = CompanyBuilder::aCompany()->persistWith($companies);
        $user = UserBuilder::aUser()->persistWith($users);

        CompanyMemberBuilder::aCompanyMember()
            ->withCompany($companyA)
            ->withUser($user)
            ->persistWith($companies, $users, $companyMembers);

        self::assertTrue($companyMembers->existsForUserAndCompany((string) $companyA->id(), (string) $user->id()));
        self::assertFalse($companyMembers->existsForUserAndCompany((string) $companyB->id(), (string) $user->id()));
    }

    public function testDuplicatePairIsRejectedByPrimaryKey(): void
    {
        self::bootKernel();
        [$companies, $users, $companyMembers] = $this->repositories();
        $entityManager = $this->entityManager();

        $member = CompanyMemberBuilder::aCompanyMember()->persistWith($companies, $users, $companyMembers);

        $this->expectException(UniqueConstraintViolationException::class);

        // (company_id, user_id) — сам первичный ключ, не отдельный
        // уникальный индекс; конфликт обязан перехватываться на вставке,
        // не проверкой "найти и вставить" (CLAUDE.md §4).
        $entityManager->getConnection()->insert('company_member', [
            'company_id' => (string) $member->companyId(),
            'user_id' => (string) $member->userId(),
            'role' => 'owner',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function testSameUserCanBeMemberOfTwoCompanies(): void
    {
        self::bootKernel();
        [$companies, $users, $companyMembers] = $this->repositories();

        $companyA = CompanyBuilder::aCompany()->persistWith($companies);
        $companyB = CompanyBuilder::aCompany()->persistWith($companies);
        $user = UserBuilder::aUser()->persistWith($users);

        CompanyMemberBuilder::aCompanyMember()->withCompany($companyA)->withUser($user)->persistWith($companies, $users, $companyMembers);
        CompanyMemberBuilder::aCompanyMember()->withCompany($companyB)->withUser($user)->persistWith($companies, $users, $companyMembers);

        self::assertTrue($companyMembers->existsForUserAndCompany((string) $companyA->id(), (string) $user->id()));
        self::assertTrue($companyMembers->existsForUserAndCompany((string) $companyB->id(), (string) $user->id()));
    }

    /**
     * @return array{0: CompanyRepository, 1: DoctrineUserRepository, 2: DoctrineCompanyMemberRepository}
     */
    private function repositories(): array
    {
        $entityManager = $this->entityManager();

        /** @var CompanyRepository $companies */
        $companies = self::getContainer()->get(CompanyRepository::class);

        return [$companies, new DoctrineUserRepository($entityManager), new DoctrineCompanyMemberRepository($entityManager)];
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
