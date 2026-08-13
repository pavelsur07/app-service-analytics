<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\AddCompanyMemberAction;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\ValueObject\CompanyMemberRole;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class AddCompanyMemberActionTest extends KernelTestCase
{
    public function testExistingUserGetsAccessToSecondCompanyWithoutLosingTheFirst(): void
    {
        self::bootKernel();
        [$companies, $users, $companyMembers] = $this->repositories();

        $first = CompanyBuilder::aCompany()->withName('First')->persistWith($companies);
        $second = CompanyBuilder::aCompany()->withName('Second')->persistWith($companies);
        $user = UserBuilder::aUser()->withEmail('owner@example.com')->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()->withCompany($first)->withUser($user)->persistWith($companies, $users, $companyMembers);

        $member = $this->action()('owner@example.com', $second->id(), CompanyMemberRole::Owner);

        self::assertNotNull($member);
        // Доступ добавляется, а не переносится: первая компания остаётся.
        self::assertTrue($companyMembers->existsForUserAndCompany($first->id()->toRfc4122(), $user->id()->toRfc4122()));
        self::assertTrue($companyMembers->existsForUserAndCompany($second->id()->toRfc4122(), $user->id()->toRfc4122()));
    }

    public function testUnknownEmailAddsNothing(): void
    {
        self::bootKernel();
        [$companies, , $companyMembers] = $this->repositories();

        $company = CompanyBuilder::aCompany()->persistWith($companies);

        // null, а не исключение и не тихое создание членства: членство
        // без пользователя вело бы в никуда.
        self::assertNull($this->action()('nobody@example.com', $company->id(), CompanyMemberRole::Owner));
        self::assertFalse($companyMembers->existsForUserAndCompany($company->id()->toRfc4122(), Uuid::v7()->toRfc4122()));
    }

    public function testRepeatedAddIsRejectedByThePrimaryKey(): void
    {
        self::bootKernel();
        [$companies, $users, $companyMembers] = $this->repositories();

        $company = CompanyBuilder::aCompany()->persistWith($companies);
        $user = UserBuilder::aUser()->withEmail('twice@example.com')->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()->withCompany($company)->withUser($user)->persistWith($companies, $users, $companyMembers);

        // Карта идентичности очищается намеренно: команда запускается
        // отдельным процессом, где её и нет. Без очистки ORM заметил бы
        // столкновение раньше базы и бросил бы своё исключение — то есть
        // тест проверял бы поведение Doctrine внутри одного процесса,
        // а не защиту, которая реально сработает в проде.
        $this->entityManager()->clear();

        // Составной первичный ключ (company_id, user_id) — защита в базе,
        // а не проверка перед вставкой (CLAUDE.md §4). Вызывающий ловит
        // конфликт и сообщает «уже состоит».
        $this->expectException(UniqueConstraintViolationException::class);
        $this->action()('twice@example.com', $company->id(), CompanyMemberRole::Owner);
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }

    private function action(): AddCompanyMemberAction
    {
        /** @var AddCompanyMemberAction $action */
        $action = static::getContainer()->get(AddCompanyMemberAction::class);

        return $action;
    }

    /**
     * @return array{0: CompanyRepository, 1: DoctrineUserRepository, 2: DoctrineCompanyMemberRepository}
     */
    private function repositories(): array
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        /** @var CompanyRepository $companies */
        $companies = static::getContainer()->get(CompanyRepository::class);

        return [
            $companies,
            new DoctrineUserRepository($entityManager),
            new DoctrineCompanyMemberRepository($entityManager),
        ];
    }
}
