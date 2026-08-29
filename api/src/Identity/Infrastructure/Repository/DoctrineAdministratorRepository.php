<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Repository;

use App\Identity\Domain\Administrator;
use App\Identity\Domain\AdministratorRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAdministratorRepository implements AdministratorRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function add(Administrator $administrator): void
    {
        $this->entityManager->persist($administrator);
        $this->entityManager->flush();
    }

    public function findByEmail(string $email): ?Administrator
    {
        $administrator = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(Administrator::class, 'a')
            ->where('a.email = :email')
            ->setParameter('email', Administrator::normalizeEmail($email))
            ->getQuery()
            ->getOneOrNullResult();

        \assert(null === $administrator || $administrator instanceof Administrator);

        return $administrator;
    }
}
