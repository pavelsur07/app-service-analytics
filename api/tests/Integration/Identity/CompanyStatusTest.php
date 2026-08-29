<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\ChangeCompanyStatusAction;
use App\Identity\Domain\Administrator;
use App\Identity\Domain\AuditAction;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\ValueObject\CompanyStatus;
use App\Identity\Infrastructure\Repository\DoctrineAdministratorRepository;
use App\Tests\Support\Builder\AdministratorBuilder;
use App\Tests\Support\Builder\CompanyBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Переходы статуса аккаунта (ADR-017).
 *
 * Проверяется не «поле поменялось», а два свойства, ради которых
 * переход сделан условным `UPDATE`: повтор ничего не меняет и не пишет
 * второй след, а сам след неотделим от перехода.
 */
final class CompanyStatusTest extends KernelTestCase
{
    public function testCompanyIsActiveWhenRegistered(): void
    {
        self::bootKernel();

        self::assertSame(CompanyStatus::Active, CompanyBuilder::aCompany()->build()->status());
    }

    public function testBlockChangesStatusAndWritesTheJournal(): void
    {
        self::bootKernel();
        $companies = $this->companies();
        $company = CompanyBuilder::aCompany()->persistWith($companies);
        $companyId = $company->id()->toRfc4122();
        $actor = $this->actor();

        self::assertTrue(($this->changeStatus())($companyId, CompanyStatus::Blocked, $actor));

        self::assertSame('blocked', $this->storedStatus($companyId));

        $record = $this->journalFor($companyId, AuditAction::CompanyBlocked);
        self::assertIsArray($record);
        self::assertSame($companyId, $record['company_id'], 'событие про конкретного арендатора');
        self::assertSame((string) $actor->id(), $record['actor_admin_id']);
        self::assertNull($record['actor_user_id']);
        self::assertSame('active', $record['previous_value']);
        self::assertSame('blocked', $record['new_value']);
    }

    public function testRepeatedBlockChangesNothingAndLeavesNoSecondTrace(): void
    {
        self::bootKernel();
        $companies = $this->companies();
        $company = CompanyBuilder::aCompany()->persistWith($companies);
        $companyId = $company->id()->toRfc4122();
        $changeStatus = $this->changeStatus();
        $actor = $this->actor();

        self::assertTrue($changeStatus($companyId, CompanyStatus::Blocked, $actor));

        // Второй раз перехода не было: условие внутри UPDATE не нашло
        // строки в состоянии active. Ни ошибки, ни второй записи.
        self::assertFalse($changeStatus($companyId, CompanyStatus::Blocked, $actor));

        self::assertSame(1, $this->journalCount($companyId, AuditAction::CompanyBlocked));
    }

    public function testActivateReturnsAccessAndWritesItsOwnTrace(): void
    {
        self::bootKernel();
        $companies = $this->companies();
        $company = CompanyBuilder::aCompany()->persistWith($companies);
        $companyId = $company->id()->toRfc4122();
        $changeStatus = $this->changeStatus();
        $actor = $this->actor();

        $changeStatus($companyId, CompanyStatus::Blocked, $actor);

        self::assertTrue($changeStatus($companyId, CompanyStatus::Active, $actor));
        self::assertSame('active', $this->storedStatus($companyId));

        $record = $this->journalFor($companyId, AuditAction::CompanyActivated);
        self::assertIsArray($record);
        self::assertSame('blocked', $record['previous_value']);
        self::assertSame('active', $record['new_value']);
    }

    public function testActivatingAnActiveCompanyIsANoOp(): void
    {
        self::bootKernel();
        $companies = $this->companies();
        $company = CompanyBuilder::aCompany()->persistWith($companies);
        $companyId = $company->id()->toRfc4122();

        self::assertFalse(($this->changeStatus())($companyId, CompanyStatus::Active, $this->actor()));
        self::assertSame(0, $this->journalCount($companyId, AuditAction::CompanyActivated));
    }

    public function testUnknownCompanyIsNotBlockedAndLeavesNoTrace(): void
    {
        self::bootKernel();
        $unknown = Uuid::v7()->toRfc4122();

        // Компании нет — UPDATE не затронул строк. Журнал не должен
        // получить запись о переходе, которого не было.
        self::assertFalse(($this->changeStatus())($unknown, CompanyStatus::Blocked, $this->actor()));
        self::assertSame(0, $this->journalCount($unknown, AuditAction::CompanyBlocked));
    }

    /**
     * Строится напрямую, не через контейнер: сценарий пока никем
     * не потребляется (контроллеры — следующий этап), и компилятор
     * вырезает неиспользуемый private-сервис. Та же причина, что
     * у DoctrineUserRepository в тестах PR1.
     */
    private function changeStatus(): ChangeCompanyStatusAction
    {
        return new ChangeCompanyStatusAction($this->companies());
    }

    private function actor(): Administrator
    {
        return AdministratorBuilder::aBootstrapSuperAdmin()
            ->withEmail('boss@conwix.local')
            ->persistWith(new DoctrineAdministratorRepository($this->entityManager()));
    }

    private function storedStatus(string $companyId): string
    {
        $status = $this->entityManager()->getConnection()->fetchOne(
            'SELECT status FROM company WHERE id = :id',
            ['id' => $companyId],
        );
        self::assertIsString($status);

        return $status;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function journalFor(string $companyId, string $action): array|false
    {
        return $this->entityManager()->getConnection()->fetchAssociative(
            'SELECT company_id, actor_user_id, actor_admin_id, previous_value, new_value FROM audit_record WHERE company_id = :id AND action = :action',
            ['id' => $companyId, 'action' => $action],
        );
    }

    private function journalCount(string $companyId, string $action): int
    {
        $count = $this->entityManager()->getConnection()->fetchOne(
            'SELECT count(*) FROM audit_record WHERE company_id = :id AND action = :action',
            ['id' => $companyId, 'action' => $action],
        );
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function companies(): CompanyRepository
    {
        /** @var CompanyRepository $companies */
        $companies = self::getContainer()->get(CompanyRepository::class);

        return $companies;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
