<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Identity\Domain\EmailVerificationToken;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ADR-005: пользователь и хэш задаются явно, чтобы билдер не скрывал
 * проверяемые в тесте границы и уникальность секрета.
 */
final class EmailVerificationTokenBuilder
{
    private ?\DateTimeImmutable $issuedAt = null;

    private function __construct(
        private readonly User $user,
        private readonly string $tokenHash,
    ) {
    }

    public static function aToken(User $user, string $tokenHash): self
    {
        return new self($user, $tokenHash);
    }

    public function withIssuedAt(\DateTimeImmutable $issuedAt): self
    {
        $clone = clone $this;
        $clone->issuedAt = $issuedAt;

        return $clone;
    }

    public function build(): EmailVerificationToken
    {
        return EmailVerificationToken::issue(
            $this->user->id(),
            $this->tokenHash,
            $this->issuedAt ?? new \DateTimeImmutable(),
        );
    }

    public function persistWith(EntityManagerInterface $entityManager): EmailVerificationToken
    {
        $token = $this->build();
        $entityManager->persist($token);
        $entityManager->flush();

        return $token;
    }
}
