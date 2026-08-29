<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Repository;

use App\Identity\Domain\CompanyMember;
use App\Identity\Domain\CompanyMemberRepository;
use App\Identity\Domain\ValueObject\CompanyStatus;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineCompanyMemberRepository implements CompanyMemberRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function add(CompanyMember $member): void
    {
        $this->entityManager->persist($member);
        $this->entityManager->flush();
    }

    /**
     * На каждом запросе к company-scoped маршруту — DBAL напрямую,
     * без гидрации сущности, которая здесь не нужна (CLAUDE.md §5).
     */
    public function existsForUserAndCompany(string $companyId, string $userId): bool
    {
        $found = $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM company_member WHERE company_id = :companyId AND user_id = :userId LIMIT 1',
            ['companyId' => $companyId, 'userId' => $userId],
        );

        return false !== $found;
    }

    /**
     * Членство и статус компании одним запросом — на пути каждого
     * company-scoped запроса (CLAUDE.md §5: DBAL, без гидрации).
     *
     * JOIN, а не два обращения: строка членства без компании
     * теоретически возможна (FK на company_id нет, консольные команды
     * существование не проверяют), и такой случай честно означает
     * «доступа нет» — JOIN не вернёт ничего, вызывающий ответит отказом.
     */
    public function findAccessStatus(string $companyId, string $userId): ?CompanyStatus
    {
        $status = $this->entityManager->getConnection()->fetchOne(
            <<<'SQL'
                SELECT c.status
                FROM company_member m
                JOIN company c ON c.id = m.company_id
                WHERE m.company_id = :companyId AND m.user_id = :userId
                LIMIT 1
                SQL,
            ['companyId' => $companyId, 'userId' => $userId],
        );

        if (!\is_string($status)) {
            return null;
        }

        return CompanyStatus::from($status);
    }
}
