<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Repository;

use App\Identity\Domain\AuditRecord;
use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyMember;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\User;
use App\Identity\Domain\ValueObject\CompanyStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineCompanyRepository implements CompanyRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function add(Company $company): void
    {
        $this->entityManager->persist($company);
        $this->entityManager->flush();
    }

    public function registerWithOwner(
        Company $company,
        User $owner,
        CompanyMember $membership,
        AuditRecord $trail,
    ): void {
        // Один flush на всё: вложенные persist попадают в одну
        // транзакцию, и падение любого из них не оставляет половины
        // аккаунта (см. интерфейс).
        $this->entityManager->wrapInTransaction(function () use ($company, $owner, $membership, $trail): void {
            $this->entityManager->persist($company);
            $this->entityManager->persist($owner);
            $this->entityManager->persist($membership);
            $this->entityManager->persist($trail);
            $this->entityManager->flush();
        });
    }

    public function blockIfActive(string $companyId, AuditRecord $trail): bool
    {
        return $this->changeStatus($companyId, CompanyStatus::Active, CompanyStatus::Blocked, $trail);
    }

    public function activateIfBlocked(string $companyId, AuditRecord $trail): bool
    {
        return $this->changeStatus($companyId, CompanyStatus::Blocked, CompanyStatus::Active, $trail);
    }

    /**
     * DBAL, не ORM: условие `status = :expected` обязано быть внутри
     * UPDATE (см. интерфейс). ORM здесь дал бы «прочитать, сверить,
     * записать» — ровно то, что запрещено.
     *
     * Транзакция явная и охватывает оба действия: переход без записи
     * в журнале и запись без перехода одинаково недопустимы. Порядок
     * внутри — сначала UPDATE, потому что его результат решает, нужен
     * ли след вообще: у повторного вызова перехода не было, и записывать
     * нечего.
     */
    private function changeStatus(
        string $companyId,
        CompanyStatus $expected,
        CompanyStatus $next,
        AuditRecord $trail,
    ): bool {
        $connection = $this->entityManager->getConnection();

        return $connection->transactional(function (Connection $connection) use ($companyId, $expected, $next, $trail): bool {
            $affected = $connection->executeStatement(
                'UPDATE company SET status = :next WHERE id = :id AND status = :expected',
                [
                    'next' => $next->value,
                    'id' => $companyId,
                    'expected' => $expected->value,
                ],
            );

            if (0 === $affected) {
                return false;
            }

            $this->entityManager->persist($trail);
            $this->entityManager->flush();

            return true;
        });
    }
}
