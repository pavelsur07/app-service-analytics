<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'administrator_mfa_recovery_code')]
#[ORM\Index(name: 'idx_admin_mfa_code_admin', columns: ['administrator_id'])]
#[ORM\UniqueConstraint(name: 'uq_admin_mfa_code_hash', columns: ['code_hash'])]
class AdministratorMfaRecoveryCode
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $administratorId;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private readonly string $codeHash;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    private function __construct(Uuid $administratorId, string $codeHash)
    {
        $this->id = Uuid::v7();
        $this->administratorId = $administratorId;
        $this->codeHash = $codeHash;
    }

    public static function issue(Uuid $administratorId, string $codeHash): self
    {
        return new self($administratorId, $codeHash);
    }
}
