<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Учётная запись человека (ADR-007). Не данные компании — доступ к ним
 * даёт CompanyMember, не User сам по себе (ADR-002).
 *
 * getRoles() всегда ['ROLE_USER']: системы разрешений нет, роль-колонка
 * живёт на CompanyMember и с Symfony-ролями не связана.
 */
#[ORM\Entity]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'uq_user_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
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

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailConfirmedAt;

    #[ORM\Column(nullable: true)]
    private readonly ?\DateTimeImmutable $legalConsentAt;

    #[ORM\Column(length: 32, nullable: true)]
    private readonly ?string $legalDocumentsVersion;

    private function __construct(
        Uuid $id,
        string $email,
        string $passwordHash,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $emailConfirmedAt,
        ?\DateTimeImmutable $legalConsentAt,
        ?string $legalDocumentsVersion,
    ) {
        $this->id = $id;
        $this->email = $email;
        $this->passwordHash = $passwordHash;
        $this->createdAt = $createdAt;
        $this->emailConfirmedAt = $emailConfirmedAt;
        $this->legalConsentAt = $legalConsentAt;
        $this->legalDocumentsVersion = $legalDocumentsVersion;
    }

    public static function register(string $email, string $passwordHash): self
    {
        $createdAt = new \DateTimeImmutable();

        return new self(
            Uuid::v7(),
            self::normalizeEmail($email),
            $passwordHash,
            $createdAt,
            $createdAt,
            null,
            null,
        );
    }

    public static function selfRegister(
        string $email,
        string $passwordHash,
        \DateTimeImmutable $consentedAt,
        string $legalDocumentsVersion,
    ): self {
        $legalDocumentsVersion = trim($legalDocumentsVersion);
        if ('' === $legalDocumentsVersion) {
            throw new \InvalidArgumentException('Legal documents version must not be empty.');
        }

        return new self(
            Uuid::v7(),
            self::normalizeEmail($email),
            $passwordHash,
            new \DateTimeImmutable(),
            null,
            $consentedAt,
            $legalDocumentsVersion,
        );
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

    public function emailConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->emailConfirmedAt;
    }

    public function legalConsentAt(): ?\DateTimeImmutable
    {
        return $this->legalConsentAt;
    }

    public function legalDocumentsVersion(): ?string
    {
        return $this->legalDocumentsVersion;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        \assert('' !== $this->email);

        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }
}
