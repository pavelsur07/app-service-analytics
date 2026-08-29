<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Identity\Domain\Administrator;
use App\Identity\Domain\AdministratorRepository;
use App\Identity\Domain\ValueObject\AdminRole;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-005: валидные умолчания, неизменяем, разделяет build()
 * и persistWith().
 *
 * Умолчание — нижняя роль: тест, которому нужен `SuperAdmin`, обязан
 * попросить его явно. Наоборот было бы опаснее — забытый вызов давал бы
 * тесту больше прав, чем он проверяет.
 */
final class AdministratorBuilder
{
    private string $email = 'ops@example.com';
    private string $passwordHash = 'stub-hash';
    private AdminRole $role = AdminRole::Admin;
    private ?Uuid $createdByAdminId = null;

    private function __construct()
    {
    }

    public static function anAdministrator(): self
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

    public function withRole(AdminRole $role): self
    {
        $clone = clone $this;
        $clone->role = $role;

        return $clone;
    }

    public function withCreatedByAdminId(?Uuid $createdByAdminId): self
    {
        $clone = clone $this;
        $clone->createdByAdminId = $createdByAdminId;

        return $clone;
    }

    public function build(): Administrator
    {
        return Administrator::create($this->email, $this->passwordHash, $this->role, $this->createdByAdminId);
    }

    public function persistWith(AdministratorRepository $repository): Administrator
    {
        $administrator = $this->build();
        $repository->add($administrator);

        return $administrator;
    }
}
