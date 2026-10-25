<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Identity\Domain\Administrator;
use App\Identity\Domain\AdministratorMfa;
use Doctrine\ORM\EntityManagerInterface;

/** Незавершённая настройка; секрет уже зашифрован вызывающим тестом. */
final class AdministratorMfaBuilder
{
    private ?\DateTimeImmutable $createdAt = null;

    private function __construct(
        private readonly Administrator $administrator,
        private readonly string $encryptedSecret,
    ) {
    }

    public static function anEnrollment(Administrator $administrator, string $encryptedSecret): self
    {
        return new self($administrator, $encryptedSecret);
    }

    public function withCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $clone = clone $this;
        $clone->createdAt = $createdAt;

        return $clone;
    }

    public function build(): AdministratorMfa
    {
        return AdministratorMfa::enroll($this->administrator->id(), $this->encryptedSecret, $this->createdAt ?? new \DateTimeImmutable());
    }

    public function persistWith(EntityManagerInterface $entityManager): AdministratorMfa
    {
        $enrollment = $this->build();
        $entityManager->persist($enrollment);
        $entityManager->flush();

        return $enrollment;
    }
}
