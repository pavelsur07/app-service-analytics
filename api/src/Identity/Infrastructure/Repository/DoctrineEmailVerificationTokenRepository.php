<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Repository;

use App\Identity\Domain\EmailVerificationToken;
use App\Identity\Domain\EmailVerificationTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineEmailVerificationTokenRepository implements EmailVerificationTokenRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function add(EmailVerificationToken $token): void
    {
        $this->entityManager->persist($token);
        $this->entityManager->flush();
    }
}
