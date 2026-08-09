<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;

/**
 * ADR-005: валидные умолчания, неизменяем, разделяет build() и persistWith().
 */
final class UserBuilder
{
    private string $email = 'owner@example.com';
    private string $passwordHash = 'stub-hash';

    private function __construct()
    {
    }

    public static function aUser(): self
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

    public function build(): User
    {
        return User::register($this->email, $this->passwordHash);
    }

    public function persistWith(UserRepository $repository): User
    {
        $user = $this->build();
        $repository->add($user);

        return $user;
    }
}
