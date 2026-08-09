<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Identity\Domain\ValueObject\CompanyMemberRole;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Ребро "человек ↔ компания" (ADR-002) — единственная точка ответа на
 * вопрос о доступе. Составной первичный ключ (company_id, user_id):
 * на эту строку никто не ссылается извне, суррогатный id не нужен
 * (ADR-003 — UUID заводится только сущностям, на которые ссылаются
 * извне). company_id первым столбцом (CLAUDE.md §1); отдельный индекс
 * на user_id обслуживает обратный запрос "какие компании доступны
 * этому пользователю", которого первый столбец составного PK не даёт.
 */
#[ORM\Entity]
#[ORM\Table(name: 'company_member')]
#[ORM\Index(name: 'idx_company_member_user_id', columns: ['user_id'])]
class CompanyMember
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $userId;

    #[ORM\Column(length: 32, enumType: CompanyMemberRole::class)]
    private readonly CompanyMemberRole $role;

    #[ORM\Column]
    private readonly \DateTimeImmutable $createdAt;

    private function __construct(Uuid $companyId, Uuid $userId, CompanyMemberRole $role, \DateTimeImmutable $createdAt)
    {
        $this->companyId = $companyId;
        $this->userId = $userId;
        $this->role = $role;
        $this->createdAt = $createdAt;
    }

    public static function create(Uuid $companyId, Uuid $userId, CompanyMemberRole $role): self
    {
        return new self($companyId, $userId, $role, new \DateTimeImmutable());
    }

    public function companyId(): Uuid
    {
        return $this->companyId;
    }

    public function userId(): Uuid
    {
        return $this->userId;
    }

    public function role(): CompanyMemberRole
    {
        return $this->role;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
