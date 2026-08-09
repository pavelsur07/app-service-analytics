<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Учётная запись человека (ADR-007). Не данные компании — доступ к ним
 * даёт CompanyMember, не User сам по себе (ADR-002).
 *
 * Не реализует Symfony UserInterface/PasswordAuthenticatedUserInterface
 * в этом пакете: security-bundle — зависимость PR2 (аутентификация),
 * здесь только схема. Интерфейсы и делегирующие методы добавляются
 * вместе с пакетом в PR2, не раньше.
 */
#[ORM\Entity]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'uq_user_email', columns: ['email'])]
class User
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private readonly string $email;

    #[ORM\Column(length: 255)]
    private string $passwordHash;

    #[ORM\Column]
    private readonly \DateTimeImmutable $createdAt;

    private function __construct(Uuid $id, string $email, string $passwordHash, \DateTimeImmutable $createdAt)
    {
        $this->id = $id;
        $this->email = $email;
        $this->passwordHash = $passwordHash;
        $this->createdAt = $createdAt;
    }

    public static function register(string $email, string $passwordHash): self
    {
        return new self(Uuid::v7(), self::normalizeEmail($email), $passwordHash, new \DateTimeImmutable());
    }

    /**
     * Одно место нормализации — используется и при сохранении, и при
     * поиске (DoctrineUserRepository::findByEmail, UserProvider), иначе
     * регистр расходится между записью и чтением.
     */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }
}
