<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Append-only выпуск ссылки подтверждения email (ADR-021).
 *
 * Ассоциации Doctrine нет намеренно: жизненный цикл токена управляется
 * сценарием регистрации, а конкурентное погашение выполняет DBAL UPDATE
 * с consumed_at IS NULL, не метод сущности после предварительного чтения.
 */
#[ORM\Entity]
#[ORM\Table(name: 'email_verification_token')]
#[ORM\UniqueConstraint(name: 'uq_email_verification_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_email_verification_token_user_id', columns: ['user_id'])]
class EmailVerificationToken
{
    public const string TTL = 'PT24H';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $userId;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private readonly string $tokenHash;

    #[ORM\Column]
    private readonly \DateTimeImmutable $issuedAt;

    #[ORM\Column]
    private readonly \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    private function __construct(
        Uuid $id,
        Uuid $userId,
        string $tokenHash,
        \DateTimeImmutable $issuedAt,
        \DateTimeImmutable $expiresAt,
    ) {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $tokenHash)) {
            throw new \InvalidArgumentException('Email verification token hash must be lowercase SHA-256.');
        }

        $this->id = $id;
        $this->userId = $userId;
        $this->tokenHash = $tokenHash;
        $this->issuedAt = $issuedAt;
        $this->expiresAt = $expiresAt;
    }

    public static function issue(Uuid $userId, string $tokenHash, \DateTimeImmutable $issuedAt): self
    {
        return new self(
            Uuid::v7(),
            $userId,
            $tokenHash,
            $issuedAt,
            $issuedAt->add(new \DateInterval(self::TTL)),
        );
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function userId(): Uuid
    {
        return $this->userId;
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function issuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function consumedAt(): ?\DateTimeImmutable
    {
        return $this->consumedAt;
    }
}
