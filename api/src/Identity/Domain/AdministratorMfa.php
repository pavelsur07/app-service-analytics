<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'administrator_mfa')]
class AdministratorMfa
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $administratorId;

    #[ORM\Column(type: 'text')]
    private readonly string $encryptedSecret;

    #[ORM\Column]
    private readonly \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $enabledAt = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $lastUsedStep = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $version = 1;

    private function __construct(Uuid $administratorId, string $encryptedSecret, \DateTimeImmutable $createdAt)
    {
        $this->administratorId = $administratorId;
        $this->encryptedSecret = $encryptedSecret;
        $this->createdAt = $createdAt;
    }

    public static function enroll(Uuid $administratorId, string $encryptedSecret, \DateTimeImmutable $now): self
    {
        return new self($administratorId, $encryptedSecret, $now);
    }
}
