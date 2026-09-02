<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;

/**
 * ADR-005: валидные умолчания, неизменяем, разделяет build() и persistWith().
 */
final class UserBuilder
{
    private string $email = 'owner@example.com';
    private string $passwordHash = 'stub-hash';
    private bool $unconfirmed = false;
    private ?\DateTimeImmutable $legalConsentAt = null;
    private ?string $legalDocumentsVersion = null;

    private function __construct()
    {
    }

    public static function aUser(): self
    {
        return new self();
    }

    public function withEmail(string $email): self
    {
        $clone = clone $this;
        $clone->email = $email;

        return $clone;
    }

    public function withPasswordHash(string $passwordHash): self
    {
        $clone = clone $this;
        $clone->passwordHash = $passwordHash;

        return $clone;
    }

    public function unconfirmed(
        ?\DateTimeImmutable $consentedAt = null,
        string $documentsVersion = '2026-09-02',
    ): self {
        $clone = clone $this;
        $clone->unconfirmed = true;
        $clone->legalConsentAt = $consentedAt ?? new \DateTimeImmutable('2026-09-02T10:00:00+00:00');
        $clone->legalDocumentsVersion = $documentsVersion;

        return $clone;
    }

    public function build(): User
    {
        if ($this->unconfirmed) {
            \assert(null !== $this->legalConsentAt);
            \assert(null !== $this->legalDocumentsVersion);

            return User::selfRegister(
                $this->email,
                $this->passwordHash,
                $this->legalConsentAt,
                $this->legalDocumentsVersion,
            );
        }

        return User::register($this->email, $this->passwordHash);
    }

    public function persistWith(UserRepository $repository): User
    {
        $user = $this->build();
        $repository->add($user);

        return $user;
    }
}
