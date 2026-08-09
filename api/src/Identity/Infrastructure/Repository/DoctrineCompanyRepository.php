<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Repository;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
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
}
