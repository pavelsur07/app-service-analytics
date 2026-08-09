<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * User не данные компании (ADR-002) — findByEmail без companyId первым
 * параметром осознанно: поиск по email межарендаторный по своей природе,
 * это и есть смысл входа.
 */
interface UserRepository
{
    public function add(User $user): void;

    public function findByEmail(string $email): ?User;
}
