<?php

declare(strict_types=1);

namespace App\Links\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Человечески редактируемая короткая ссылка (ADR-022).
 *
 * Автор хранится скалярным UUID без межмодульной ORM-ассоциации.
 * Изменения имени, цели и статуса выполняются через явные операции;
 * optimistic version не даёт двум администраторам молча затереть
 * правки друг друга (ADR-008).
 */
#[ORM\Entity]
#[ORM\Table(name: 'short_link')]
#[ORM\UniqueConstraint(name: 'uq_short_link_code', columns: ['code'])]
#[ORM\Index(name: 'idx_short_link_created', columns: ['created_at', 'id'])]
#[ORM\Index(name: 'idx_short_link_created_by', columns: ['created_by_admin_id'])]
class ShortLink
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $id;

    #[ORM\Column(length: 7)]
    private readonly string $code;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 2048)]
    private string $targetUrl;

    #[ORM\Column(length: 16, enumType: ShortLinkStatus::class)]
    private ShortLinkStatus $status;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $createdByAdminId;

    #[ORM\Column]
    private readonly \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        Uuid $id,
        string $code,
        string $name,
        string $targetUrl,
        ShortLinkStatus $status,
        Uuid $createdByAdminId,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->code = $code;
        $this->name = $name;
        $this->targetUrl = $targetUrl;
        $this->status = $status;
        $this->createdByAdminId = $createdByAdminId;
        $this->createdAt = $createdAt;
        $this->updatedAt = $createdAt;
    }

    public static function create(
        string $code,
        string $name,
        string $targetUrl,
        Uuid $createdByAdminId,
        \DateTimeImmutable $at,
    ): self {
        if (1 !== preg_match('/^[0-9A-Za-z]{7}$/D', $code)) {
            throw new \InvalidArgumentException('Short link code must contain exactly seven base62 characters.');
        }

        return new self(
            Uuid::v7(),
            $code,
            $name,
            $targetUrl,
            ShortLinkStatus::Active,
            $createdByAdminId,
            $at,
        );
    }

    public function changeDetails(string $name, string $targetUrl, \DateTimeImmutable $at): bool
    {
        if ($this->name === $name && $this->targetUrl === $targetUrl) {
            return false;
        }

        $this->name = $name;
        $this->targetUrl = $targetUrl;
        $this->updatedAt = $at;

        return true;
    }

    public function changeStatus(ShortLinkStatus $status, \DateTimeImmutable $at): bool
    {
        if ($this->status === $status) {
            return false;
        }

        $this->status = $status;
        $this->updatedAt = $at;

        return true;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function targetUrl(): string
    {
        return $this->targetUrl;
    }

    public function status(): ShortLinkStatus
    {
        return $this->status;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function createdByAdminId(): Uuid
    {
        return $this->createdByAdminId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
