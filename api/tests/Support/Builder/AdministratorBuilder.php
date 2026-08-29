<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Identity\Domain\Administrator;
use App\Identity\Domain\AdministratorRepository;
use App\Identity\Domain\ValueObject\AdminRole;

/**
 * ADR-005: валидные умолчания, неизменяем, разделяет build()
 * и persistWith(), связанные сущности создаёт через их билдеры.
 *
 * Умолчание — нижняя роль: тест, которому нужен `SuperAdmin`, обязан
 * попросить его явно. Наоборот было бы опаснее — забытый вызов давал бы
 * тесту больше прав, чем он проверяет.
 *
 * **Автор обязателен всем, кроме первого администратора.** База держит
 * это тремя ограничениями (`chk_administrator_author`,
 * `fk_administrator_author`, `uq_administrator_bootstrap`), поэтому
 * подставить сюда случайный uuid нельзя — автор должен существовать.
 * Не задали автора — `persistWith()` заведёт bootstrap-администратора
 * его же билдером.
 *
 * Строка без автора в таблице возможна ровно одна, поэтому нескольким
 * администраторам в одном тесте автор передаётся явно, одним и тем же:
 * `->createdBy($boss)`.
 */
final class AdministratorBuilder
{
    private string $email = 'ops@example.com';
    private string $passwordHash = 'stub-hash';
    private AdminRole $role = AdminRole::Admin;
    private ?Administrator $createdBy = null;
    private bool $bootstrap = false;

    private function __construct()
    {
    }

    public static function anAdministrator(): self
    {
        return new self();
    }

    /**
     * Первый администратор системы: верхняя роль, автора нет.
     */
    public static function aBootstrapSuperAdmin(): self
    {
        $builder = new self();
        $builder->role = AdminRole::SuperAdmin;
        $builder->bootstrap = true;

        return $builder;
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

    public function createdBy(Administrator $author): self
    {
        $clone = clone $this;
        $clone->createdBy = $author;
        $clone->bootstrap = false;

        return $clone;
    }

    /**
     * Без автора — только для bootstrap: собранная так строка
     * с нижней ролью базой не принимается, и это правильно.
     */
    public function build(): Administrator
    {
        return Administrator::create(
            $this->email,
            $this->passwordHash,
            $this->role,
            $this->createdBy?->id(),
        );
    }

    public function persistWith(AdministratorRepository $administrators): Administrator
    {
        $builder = $this;
        if (!$this->bootstrap && null === $this->createdBy) {
            $builder = $this->createdBy(
                self::aBootstrapSuperAdmin()
                    ->withEmail('bootstrap+'.$this->email)
                    ->persistWith($administrators),
            );
        }

        $administrator = $builder->build();
        $administrators->add($administrator);

        return $administrator;
    }
}
