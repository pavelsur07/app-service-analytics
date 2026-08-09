<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Repository;

use App\Identity\Domain\CompanyMember;
use App\Identity\Domain\CompanyMemberRepository;
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
}
